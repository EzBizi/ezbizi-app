<?php

namespace App\Http\Controllers\Api;

use App\Models\Complaint;
use App\Models\ComplaintNote;
use App\Models\Lead;
use App\Models\Review;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Customer Experience › Complaints (Jose, 2026-10-20; UI queue: option 1).
 *
 * The customer's words and name are read from the review or lead each time,
 * never copied, so erasing a customer there takes them off this list too.
 * Reach and the current view decide which complaints are listed, as for
 * reviews: a branch sees its own, and an organization-wide one (no company,
 * no franchise) belongs to everybody who can see the tab.
 */
class ComplaintsController extends Controller
{
    private const VIEW = 'customer_experience.complaints.view';

    private const MANAGE = 'customer_experience.complaints.manage';

    /** GET /complaints?status=open|new|in_progress|solved|not_solvable|all&kind&owner&branch&from&to&q */
    public function index(Request $request): JsonResponse
    {
        $user = $this->must($request, self::VIEW, 'see complaints');
        $query = $this->visible($user);

        if (in_array($kind = $request->query('kind'), Complaint::KINDS, true)) {
            $query->where('kind', $kind);
        }
        if (is_string($owner = $request->query('owner')) && $owner !== '') {
            $owner === 'none' ? $query->whereNull('owner_user_id') : $query->where('owner_user_id', $owner);
        }
        if (is_string($branch = $request->query('branch')) && $branch !== '') {
            $query->where('franchise_id', $branch);
        }
        if (is_string($from = $request->query('from')) && strtotime($from)) {
            $query->where('raised_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if (is_string($to = $request->query('to')) && strtotime($to)) {
            $query->where('raised_at', '<=', Carbon::parse($to)->endOfDay());
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $like = '%'.addcslashes(trim($q), '%_').'%';
            $query->where(fn ($w) => $w
                ->where(fn ($r) => $r->where('source_type', 'review')->whereIn('source_id',
                    Review::query()->where(fn ($t) => $t->where('reviewer_name', 'like', $like)->orWhere('comment', 'like', $like))->select('id')))
                ->orWhere(fn ($l) => $l->where('source_type', 'lead')->whereIn('source_id',
                    Lead::query()->where(fn ($t) => $t->where('contact_name', 'like', $like)->orWhere('summary', 'like', $like))->select('id'))));
        }

        $counts = (clone $query)->reorder()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $counts = collect(Complaint::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]);

        // The open priority ones under the same filters, soonest due first,
        // whatever page or status is shown (design 202: "Due within 24 h").
        $due = (clone $query)->where('priority', true)->whereIn('status', Complaint::OPEN)
            ->with(['owner:id,name', 'franchise:id,name'])->withCount('notes')
            ->reorder()->orderBy('due_at')->limit(20)->get();
        $dueSources = $this->sources($due);

        $query->with(['owner:id,name', 'franchise:id,name'])->withCount('notes');
        $status = $request->query('status', 'open');
        match (true) {
            $status === 'open' => $query->whereIn('status', Complaint::OPEN),
            in_array($status, Complaint::STATUSES, true) => $query->where('status', $status),
            default => null,
        };

        $page = $query->orderByDesc('raised_at')->paginate(min((int) $request->query('per_page', 50), 200));
        $sources = $this->sources(collect($page->items()));

        return response()->json([
            'data' => array_map(fn (Complaint $c) => $this->present($c, $sources), $page->items()),
            'meta' => [
                ...$this->pageMeta($page),
                'counts' => [...$counts->all(), 'open' => $counts['new'] + $counts['in_progress'], 'all' => $counts->sum()],
                'due' => $due->map(fn (Complaint $c) => $this->present($c, $dueSources))->values()->all(),
                // Who the owner picker can name, for those who may set one (Scheduling's shape).
                'people' => $user->hasPermission(self::MANAGE)
                    ? User::query()->where('organization_id', $user->organization_id)->where('is_active', true)
                        ->orderBy('name')->get(['id', 'name'])
                    : [],
            ],
        ]);
    }

    /** GET /complaints/{complaint} */
    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        $this->reach($this->must($request, self::VIEW, 'see complaints'), $complaint);

        return $this->one($complaint);
    }

    /**
     * POST /complaints {kind, title, summary?, franchise_id?, raised_at?, client?} — one that came by letter,
     * phone or a site with no API (BBB, Attorney General, a lawsuit, Yelp), entered by hand (Shoval, 2026-11-20).
     */
    public function store(Request $request, EventEmitter $events, AuditLogger $audit): JsonResponse
    {
        $user = $this->must($request, self::MANAGE, 'add complaints');
        $input = $request->validate([
            'kind' => ['required', Rule::in(['bbb', 'attorney_general', 'lawsuit', 'other'])],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'franchise_id' => ['nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $user->organization_id)],
            'raised_at' => ['nullable', 'date', 'before_or_equal:now'],
            'client' => ['nullable', 'boolean'],
        ]);
        $franchise = isset($input['franchise_id']) ? \App\Models\Franchise::query()->find($input['franchise_id']) : null;
        // An if, not abort_if: abort_if builds its message first, and with no branch
        // `$franchise->name` read null and every branchless complaint was a 500.
        if ($franchise !== null && ! $user->canActOnFranchise($franchise)) {
            abort(403, Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add complaints', $franchise->name)->message());
        }
        $id = (string) \Illuminate\Support\Str::uuid7();
        $complaint = new Complaint(['organization_id' => $user->organization_id, 'company_id' => $franchise?->company_id,
            'franchise_id' => $franchise?->id, 'kind' => $input['kind'], 'status' => 'new', 'title' => $input['title'],
            'summary' => $input['summary'] ?? null, 'raised_at' => isset($input['raised_at']) ? \Carbon\Carbon::parse($input['raised_at']) : now(),
            'client_status' => array_key_exists('client', $input) && $input['client'] !== null ? ($input['client'] ? 'client' : 'not_client') : null]);
        $complaint->forceFill(['id' => $id, 'source_type' => 'manual', 'source_id' => $id]);
        \App\Support\CustomerExperience\ComplaintTriage::apply($complaint)->save();
        $audit->log('complaint.created', $complaint);
        $events->emit('complaint.opened', $complaint, ['after' => $complaint->only(['kind', 'rating', 'raised_at', 'client_status', 'priority', 'due_at'])], $user);

        return $this->one($complaint)->setStatusCode(201);
    }

    /** PATCH /complaints/{complaint} {status?, owner_user_id?} */
    public function update(Request $request, Complaint $complaint, AuditLogger $audit, EventEmitter $events): JsonResponse
    {
        $user = $this->must($request, self::MANAGE, 'work on complaints');
        $this->reach($user, $complaint);
        $input = $request->validate([
            'status' => ['sometimes', Rule::in(Complaint::STATUSES)],
            'owner_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')
                ->where('organization_id', $user->organization_id)->where('is_active', true)],
        ]);

        $before = $complaint->status;
        $complaint->fill($input);
        if (array_key_exists('status', $input)) {
            $complaint->solved_at = in_array($input['status'], ['solved', 'not_solvable'], true) ? ($complaint->solved_at ?? now()) : null;
        }
        $complaint->save();

        if ($complaint->wasChanged()) {
            $audit->log('complaint.updated', $complaint, metadata: array_intersect_key($complaint->getChanges(), $input));
        }
        if ($complaint->wasChanged('status')) {
            $events->emit('complaint.status_changed', $complaint, [
                'before' => ['status' => $before], 'after' => ['status' => $complaint->status, 'kind' => $complaint->kind],
            ], $user);
        }

        return $this->one($complaint);
    }

    /** POST /complaints/{complaint}/notes {text} */
    public function addNote(Request $request, Complaint $complaint, AuditLogger $audit): JsonResponse
    {
        $user = $this->must($request, self::MANAGE, 'work on complaints');
        $this->reach($user, $complaint);
        $text = trim((string) $request->validate(['text' => ['required', 'string', 'max:4000']])['text']);
        $complaint->notes()->create(['organization_id' => $complaint->organization_id, 'user_id' => $user->getKey(),
            'body' => $text, 'created_at' => now()]);
        $audit->log('complaint.note_added', $complaint, metadata: ['length' => strlen($text)]);

        return $this->one($complaint);
    }

    private function one(Complaint $complaint): JsonResponse
    {
        $complaint->load(['owner:id,name', 'franchise:id,name'])->loadCount('notes');

        return response()->json(['data' => [
            ...$this->present($complaint, $this->sources(collect([$complaint]))),
            'notes' => $complaint->notes()->with('user:id,name')->get()->map(fn (ComplaintNote $n) => [
                'id' => $n->id, 'at' => $n->created_at?->toIso8601String(), 'by' => $n->user?->name, 'text' => $n->body,
            ])->all(),
        ]]);
    }

    /** @param array{review: Collection, lead: Collection, calls: Collection} $sources */
    private function present(Complaint $c, array $sources): array
    {
        $source = $sources[$c->source_type][$c->source_id] ?? null;
        [$title, $summary, $phone] = match (true) {
            $source instanceof Review => [$source->reviewer_name, $source->comment, null],
            // A complaint call shows what was said on THAT call, not the lead's first call,
            // which is usually the booking (UI audit 2026-11-04: the queue read as bookings).
            $source instanceof Lead => [$source->contact_name,
                $c->kind === 'call_complaint' ? ($this->callSummary($sources['calls'][$c->source_id] ?? collect(), $c) ?? $source->summary) : $source->summary,
                $source->phone],
            // Email and hand-entered complaints keep their own words (Shoval, 2026-11-20).
            default => [$c->title, $c->summary, null],
        };
        $own = in_array($c->source_type, ['email', 'manual'], true);

        return [
            'id' => $c->id, 'kind' => $c->kind, 'status' => $c->status,
            'title' => $title, 'summary' => $summary, 'rating' => $c->rating,
            // A caller who never gave a name still has a number (desk, 2026-10-20).
            'phone' => $phone,
            // Only when the review or lead itself is gone: a nameless one is not.
            'source_missing' => $source === null && ! $own,
            'branch' => $c->franchise?->only(['id', 'name']),
            'owner' => $c->owner?->only(['id', 'name']),
            'raised_at' => $c->raised_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
            'solved_at' => $c->solved_at?->toIso8601String(), 'note_count' => (int) ($c->notes_count ?? 0),
            'source' => ['type' => $c->source_type, 'id' => $c->source_id],
            'client_status' => $c->client_status,
            'priority' => (bool) $c->priority,
            'due_at' => $c->due_at?->toIso8601String(),
            // Google has no API to report a review (v4 reviews: list, get, updateReply, deleteReply only), so a
            // review from someone who is not a client carries the evidence and the one link a person clicks.
            'google_report' => $source instanceof Review && $c->client_status === 'not_client' && ($source->source ?? 'google') === 'google' ? [
                'url' => 'https://support.google.com/business/workflow/9945796',
                'evidence' => 'No customer, job or call matches "'.($source->reviewer_name ?? 'this reviewer')
                    .'" at this business, so it looks like the review is not from a customer. Reason to choose: Conflict of interest / not a real experience.',
            ] : null,
        ];
    }

    /** @return array{review: Collection, lead: Collection} */
    private function sources(Collection $complaints): array
    {
        $ids = fn (string $type) => $complaints->where('source_type', $type)->pluck('source_id')->all();

        return [
            'review' => Review::query()->whereIn('id', $ids('review'))->get(['id', 'reviewer_name', 'comment'])->keyBy('id'),
            'lead' => Lead::query()->whereIn('id', $ids('lead'))->get(['id', 'contact_name', 'summary', 'phone'])->keyBy('id'),
            'calls' => \App\Models\AutomationEvent::query()->where('event_key', 'lead.call_complaint')
                ->whereIn('subject_id', $complaints->where('kind', 'call_complaint')->pluck('source_id')->all())
                ->get(['subject_id', 'occurred_at', 'payload'])->groupBy('subject_id'),
        ];
    }

    /** The complaint call's own summary: the call that raised it, else the lead's latest complaint call. */
    private function callSummary(Collection $calls, Complaint $c): ?string
    {
        $call = $calls->first(fn ($e) => $e->occurred_at?->equalTo($c->raised_at)) ?? $calls->sortByDesc('occurred_at')->first();
        $summary = trim((string) ($call?->payload['after']['summary'] ?? ''));

        return $summary === '' ? null : $summary;
    }

    /** Reach, then the current view: what the list shows and what may be opened. */
    private function visible(User $user): Builder
    {
        // Reach, then the view (Places): the same cut every Customer Experience list makes.
        $query = \App\Support\CustomerExperience\Places::scope(Complaint::query(), $user);

        return $query;
    }

    private function reach(User $user, Complaint $complaint): void
    {
        abort_unless($this->visible($user)->whereKey($complaint->getKey())->exists(), 404);
    }

    private function must(Request $request, string $permission, string $act): User
    {
        $user = $request->user();
        abort_unless($user->hasPermission($permission), 403, Refusal::permission($act)->message());

        return $user;
    }
}
