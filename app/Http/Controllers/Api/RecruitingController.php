<?php

namespace App\Http\Controllers\Api;

use App\Models\Applicant;
use App\Models\ApplicantInterview;
use App\Models\Assignment;
use App\Models\RecruitingSpend;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * The applicant pipeline (Shoval, 2026-11-20): applicants by position, source
 * and stage, their interviews, and recruiting spend per month and position —
 * what the Recruiting report reads.
 *
 * Read with `hr.view`, written with `hr.edit`. Rows are limited to the branches
 * in the reader's current view; a row with no branch only to someone who
 * reaches the whole organization, as with unplaced people on Records.
 */
class RecruitingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsResolver $settings,
        private readonly ScopeReach $reach,
    ) {}

    // ── applicants ───────────────────────────────────────────────

    /** GET /api/v1/hr/applicants?position=&stage=&source=&franchise_id=&from=&to=&search= */
    public function index(Request $request): JsonResponse
    {
        $this->gate($request, 'hr.view', 'see applicants');

        $filtered = $this->visible(Applicant::query(), $request->user())
            ->when($request->query('position'), fn ($q, $v) => $q->where('position', $v))
            ->when($request->query('stage'), fn ($q, $v) => $q->where('stage', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->query('franchise_id'), fn ($q, $v) => $q->where('franchise_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('applied_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('applied_at', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->when($request->query('search'), fn ($q, $v) => $q->where(fn ($s) => $s
                ->where('name', 'like', "%{$v}%")->orWhere('phone', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")));

        $rows = (clone $filtered)->withCount('interviews')->orderByDesc('applied_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        // Over the whole filtered set, not the page (UI-UX, 2026-11-20: the chips).
        $waits = (clone $filtered)->whereNotNull('first_response_at')->get(['applied_at', 'first_response_at'])
            ->map(fn (Applicant $a) => max(0, (int) $a->applied_at->diffInMinutes($a->first_response_at)))->sort()->values();

        return response()->json([
            'data' => collect($rows->items())->map(fn (Applicant $a) => $this->present($a))->all(),
            'meta' => ['total' => $rows->total(), 'last_page' => $rows->lastPage(), 'page' => $rows->currentPage(),
                'relevant' => (clone $filtered)->where('relevant', true)->count(),
                'interviewed' => (clone $filtered)->has('interviews')->count(),
                'hired' => $this->hired(clone $filtered)->count(),
                'median_first_response_minutes' => $waits->isEmpty() ? null : (int) $waits->median(),
            ],
            'lists' => $this->lists($request->user()),
        ]);
    }

    /** GET /api/v1/hr/applicants/{applicant} — with its interviews. */
    public function show(Request $request, Applicant $applicant): JsonResponse
    {
        $this->gate($request, 'hr.view', 'see applicants');
        $this->assertVisible($applicant, $request->user());

        return response()->json(['data' => $this->present($applicant->load('interviews')) + [
            'interviews' => $applicant->interviews->map(fn ($i) => $this->presentInterview($i))->all(),
        ]]);
    }

    /** POST /api/v1/hr/applicants */
    public function store(Request $request): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'add applicants');
        $input = $this->validateApplicant($request, creating: true);
        $this->assertBranch($actor, $input['franchise_id'] ?? null);

        $input['applied_at'] ??= now();
        $input['stage'] ??= $this->values($actor, 'stages')[0] ?? 'applied';
        $applicant = Applicant::create($input + ['created_by_user_id' => $actor->id, 'stage_changed_at' => now()]);
        $this->audit->log('applicant.created', $applicant, actor: $actor);

        return response()->json(['data' => $this->present($applicant)], Status::HTTP_CREATED);
    }

    /** PATCH /api/v1/hr/applicants/{applicant} */
    public function update(Request $request, Applicant $applicant): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'change applicants');
        $this->assertVisible($applicant, $actor);
        $input = $this->validateApplicant($request, creating: false);
        if (array_key_exists('franchise_id', $input)) {
            $this->assertBranch($actor, $input['franchise_id']);
        }

        $applicant->fill($input);
        if ($applicant->isDirty('stage')) {
            $applicant->stage_changed_at = now();
            // Any move past the first stage is the organization answering them.
            $applicant->first_response_at ??= now();
        }
        $changed = array_keys($applicant->getDirty());
        $applicant->save();
        if ($changed !== []) {
            $this->audit->log('applicant.updated', $applicant, metadata: ['fields' => $changed], actor: $actor);
        }

        return response()->json(['data' => $this->present($applicant)]);
    }

    /** DELETE /api/v1/hr/applicants/{applicant} — and their interviews. */
    public function destroy(Request $request, Applicant $applicant): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'delete applicants');
        $this->assertVisible($applicant, $actor);
        $this->audit->log('applicant.deleted', $applicant, actor: $actor);
        $applicant->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── interviews ───────────────────────────────────────────────

    /** POST /api/v1/hr/applicants/{applicant}/interviews */
    public function storeInterview(Request $request, Applicant $applicant): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'record interviews');
        $this->assertVisible($applicant, $actor);
        $input = $this->validateInterview($request, $actor, creating: true);

        $interview = $applicant->interviews()->create($input);
        $applicant->forceFill(['first_response_at' => $applicant->first_response_at ?? now()])->save();
        $this->audit->log('applicant.interview_added', $applicant, metadata: ['kind' => $interview->kind], actor: $actor);

        return response()->json(['data' => $this->presentInterview($interview)], Status::HTTP_CREATED);
    }

    /** PATCH /api/v1/hr/applicant-interviews/{interview} */
    public function updateInterview(Request $request, ApplicantInterview $interview): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'record interviews');
        $this->assertVisible($interview->applicant, $actor);
        $interview->update($this->validateInterview($request, $actor, creating: false));

        return response()->json(['data' => $this->presentInterview($interview)]);
    }

    /** DELETE /api/v1/hr/applicant-interviews/{interview} */
    public function destroyInterview(Request $request, ApplicantInterview $interview): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'record interviews');
        $this->assertVisible($interview->applicant, $actor);
        $interview->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── spend ────────────────────────────────────────────────────

    /** GET /api/v1/hr/recruiting-spend?from=YYYY-MM&to=YYYY-MM&position= */
    public function spend(Request $request): JsonResponse
    {
        $this->gate($request, 'hr.view', 'see recruiting spend');

        $rows = $this->visible(RecruitingSpend::query(), $request->user())
            ->when($request->query('from'), fn ($q, $v) => $q->where('month', '>=', $v.'-01'))
            ->when($request->query('to'), fn ($q, $v) => $q->where('month', '<=', $v.'-01'))
            ->when($request->query('position'), fn ($q, $v) => $q->where('position', $v))
            ->orderByDesc('month')->orderBy('position')->get();

        // Hires per position over the same months, for cost per hire (UI-UX, 2026-11-20).
        $hires = $this->hired($this->visible(Applicant::query(), $request->user()))
            ->when($request->query('from'), fn ($q, $v) => $q->where('applied_at', '>=', $v.'-01'))
            ->when($request->query('to'), fn ($q, $v) => $q->where('applied_at', '<', date('Y-m-01', strtotime($v.'-01 +1 month'))))
            ->when($request->query('position'), fn ($q, $v) => $q->where('position', $v))
            ->selectRaw('position, count(*) as n')->groupBy('position')->pluck('n', 'position')
            ->map(fn ($n) => (int) $n);

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->presentSpend($r))->all(),
            'meta' => ['hires' => (object) $hires->all()],
        ]);
    }

    /** POST /api/v1/hr/recruiting-spend */
    public function storeSpend(Request $request): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'record recruiting spend');
        $input = $this->validateSpend($request, $actor, creating: true);
        $this->assertBranch($actor, $input['franchise_id'] ?? null);

        $row = RecruitingSpend::create($input);
        $this->audit->log('recruiting_spend.created', $row, actor: $actor);

        return response()->json(['data' => $this->presentSpend($row)], Status::HTTP_CREATED);
    }

    /** PATCH /api/v1/hr/recruiting-spend/{spend} */
    public function updateSpend(Request $request, RecruitingSpend $spend): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'record recruiting spend');
        $this->assertVisible($spend, $actor);
        $input = $this->validateSpend($request, $actor, creating: false);
        if (array_key_exists('franchise_id', $input)) {
            $this->assertBranch($actor, $input['franchise_id']);
        }
        $spend->update($input);
        $this->audit->log('recruiting_spend.updated', $spend, actor: $actor);

        return response()->json(['data' => $this->presentSpend($spend)]);
    }

    /** DELETE /api/v1/hr/recruiting-spend/{spend} */
    public function destroySpend(Request $request, RecruitingSpend $spend): JsonResponse
    {
        $actor = $this->gate($request, 'hr.edit', 'record recruiting spend');
        $this->assertVisible($spend, $actor);
        $this->audit->log('recruiting_spend.deleted', $spend, actor: $actor);
        $spend->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── shared ───────────────────────────────────────────────────

    /** Hired: moved to the "hired" stage, or linked to the account they became. */
    private function hired(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('stage', 'hired')->orWhereNotNull('hired_user_id'));
    }

    private function gate(Request $request, string $permission, string $verb): User
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission($permission), Status::HTTP_FORBIDDEN, Refusal::permission($verb)->message());

        return $actor;
    }

    /** Rows in the reader's view: their branches, and branchless only for whole-organization reach. */
    private function visible(Builder $query, User $actor): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($actor, $table) {
            $q->whereIn("{$table}.franchise_id", ViewScope::forUser($actor)->franchiseIds());
            if ($this->reach->covers($actor, Assignment::SCOPE_ORGANIZATION, $actor->organization_id)) {
                $q->orWhereNull("{$table}.franchise_id");
            }
        });
    }

    private function assertVisible(Applicant|RecruitingSpend $row, User $actor): void
    {
        abort_unless($this->visible($row->newQuery()->whereKey($row->getKey()), $actor)->exists(), Status::HTTP_NOT_FOUND);
    }

    private function assertBranch(User $actor, ?string $franchiseId): void
    {
        if ($franchiseId === null) {
            abort_unless($this->reach->covers($actor, Assignment::SCOPE_ORGANIZATION, $actor->organization_id),
                Status::HTTP_UNPROCESSABLE_ENTITY, 'Choose a branch: you can only add rows for the branches you work at.');

            return;
        }
        $this->reach->assertCovers($actor, Assignment::SCOPE_FRANCHISE, $franchiseId, 'franchise_id');
    }

    /** @return string[] the values of one recruiting list */
    private function values(User $actor, string $list): array
    {
        return array_values(array_filter(array_map(
            fn ($o) => is_array($o) ? ($o['value'] ?? null) : $o,
            (array) ($this->lists($actor)[$list] ?? []),
        )));
    }

    /** The tenant's three lists, each spelled out so a reader of the settings can find them. */
    private function lists(User $actor): array
    {
        $org = $actor->organization;

        return [
            'positions' => $this->settings->get('hr.recruiting.positions', $org),
            'stages' => $this->settings->get('hr.recruiting.stages', $org),
            'sources' => $this->settings->get('hr.recruiting.sources', $org),
        ];
    }

    private function validateApplicant(Request $request, bool $creating): array
    {
        $actor = $request->user();
        $need = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$need, 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'position' => [$need, 'string', Rule::in($this->values($actor, 'positions'))],
            'source' => ['sometimes', 'nullable', 'string', Rule::in($this->values($actor, 'sources'))],
            'stage' => ['sometimes', 'string', Rule::in($this->values($actor, 'stages'))],
            'relevant' => ['sometimes', 'nullable', 'boolean'],
            'applied_at' => ['sometimes', 'date'],
            'franchise_id' => ['sometimes', 'nullable', 'uuid'],
            'lead_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('leads', 'id')->where('organization_id', $actor->organization_id)],
            'hired_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('organization_id', $actor->organization_id)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], ['position.in' => 'Pick a position from Settings › Lists › Recruiting positions.']);
    }

    private function validateInterview(Request $request, User $actor, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'kind' => [$need, Rule::in(ApplicantInterview::KINDS)],
            'scheduled_at' => [$need, 'date'],
            'held' => ['sometimes', 'nullable', 'boolean'],
            'outcome' => ['sometimes', 'nullable', Rule::in(ApplicantInterview::OUTCOMES)],
            'interviewer_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('organization_id', $actor->organization_id)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
    }

    private function validateSpend(Request $request, User $actor, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';
        $input = $request->validate([
            'month' => [$need, 'date_format:Y-m'],
            'position' => [$need, 'string', Rule::in($this->values($actor, 'positions'))],
            'source' => ['sometimes', 'nullable', 'string', Rule::in($this->values($actor, 'sources'))],
            'amount' => [$need, 'numeric', 'min:0', 'max:9999999'],
            'franchise_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (isset($input['month'])) {
            $input['month'] .= '-01';
        }

        return $input;
    }

    private function present(Applicant $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'phone' => $a->phone,
            'email' => $a->email,
            'position' => $a->position,
            'source' => $a->source,
            'stage' => $a->stage,
            'relevant' => $a->relevant,
            'franchise_id' => $a->franchise_id,
            'lead_id' => $a->lead_id,
            'hired_user_id' => $a->hired_user_id,
            'applied_at' => $a->applied_at?->toIso8601String(),
            'first_response_at' => $a->first_response_at?->toIso8601String(),
            'stage_changed_at' => $a->stage_changed_at?->toIso8601String(),
            'notes' => $a->notes,
            'interviews_count' => $a->interviews_count ?? null,
        ];
    }

    private function presentInterview(ApplicantInterview $i): array
    {
        return [
            'id' => $i->id,
            'applicant_id' => $i->applicant_id,
            'kind' => $i->kind,
            'scheduled_at' => $i->scheduled_at?->toIso8601String(),
            'held' => $i->held,
            'outcome' => $i->outcome,
            'interviewer_user_id' => $i->interviewer_user_id,
            'notes' => $i->notes,
        ];
    }

    private function presentSpend(RecruitingSpend $s): array
    {
        return [
            'id' => $s->id,
            'month' => $s->month?->format('Y-m'),
            'position' => $s->position,
            'source' => $s->source,
            'amount' => (string) $s->amount,
            'franchise_id' => $s->franchise_id,
            'note' => $s->note,
        ];
    }
}
