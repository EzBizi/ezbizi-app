<?php

namespace App\Http\Controllers\Api;

use App\Models\SchedulingTechnician;
use App\Models\ServiceArea;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Scheduling › Settings › Technicians: EzBizi's own roster (Jose, 2026-10-27:
 * EzBizi is the master). Who can be put on a job, whether they work in the
 * field, the service areas they cover, and the EzBizi login they are, if any.
 * Seeded once from Workiz; every change here is EzBizi's and is never
 * overwritten by an import. Changing it takes `scheduling.edit`.
 */
final class SchedulingRosterController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /scheduling/roster[?franchise_id=][&all=1] */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);
        $franchises = $this->franchises($request, $request->query('franchise_id'));

        return response()->json(['data' => SchedulingTechnician::query()->with(['areas:id,name', 'user:id,name,email'])
            ->whereIn('franchise_id', $franchises)
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get()->map(fn (SchedulingTechnician $t) => $this->shown($t))->values()]);
    }

    /** POST /scheduling/roster {franchise_id, name, email?, phone?, user_id?, is_field_tech?, area_ids?} */
    public function store(Request $request): JsonResponse
    {
        $this->editor($request);
        $data = $request->validate($this->rules() + ['franchise_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120']]);
        $this->franchises($request, $data['franchise_id']);
        $tech = SchedulingTechnician::create(collect($data)->except('area_ids')->all());
        $this->areas($tech, $data['area_ids'] ?? []);
        $this->audit->log('scheduling.technician_created', $tech, metadata: ['name' => $tech->name]);

        return response()->json(['data' => $this->shown($tech->fresh(['areas', 'user']))], 201);
    }

    /** PATCH /scheduling/roster/{technician} — any of the fields, is_active, area_ids */
    public function update(Request $request, string $technician): JsonResponse
    {
        $this->editor($request);
        $tech = SchedulingTechnician::query()->whereIn('franchise_id', $this->franchises($request, null))->findOrFail($technician);
        $data = $request->validate($this->rules() + ['name' => ['sometimes', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean']]);
        $tech->update(collect($data)->except('area_ids')->all());
        if (array_key_exists('area_ids', $data)) {
            $this->areas($tech, $data['area_ids'] ?? []);
        }
        $this->audit->log('scheduling.technician_changed', $tech, metadata: collect($data)->except('area_ids')->all());

        return response()->json(['data' => $this->shown($tech->fresh(['areas', 'user']))]);
    }

    private function rules(): array
    {
        return [
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
            'is_field_tech' => ['sometimes', 'boolean'],
            'area_ids' => ['sometimes', 'array'],
            'area_ids.*' => ['uuid'],
        ];
    }

    /** Only the technician's own branch's areas. */
    private function areas(SchedulingTechnician $tech, array $ids): void
    {
        $tech->areas()->sync(ServiceArea::query()->where('franchise_id', $tech->franchise_id)->whereIn('id', $ids)->pluck('id')->all());
    }

    /** @return list<string> */
    private function franchises(Request $request, ?string $one): array
    {
        $inView = ViewScope::forUser($request->user())->franchiseIds();
        if ($one === null) {
            return $inView;
        }
        abort_unless(in_array($one, $inView, true), 404);

        return [$one];
    }

    private function editor(Request $request): void
    {
        abort_unless($request->user()->hasPermission('scheduling.edit'), 403, 'Changing technicians needs the right to edit Scheduling.');
        if ($request->filled('user_id')) {
            abort_unless(User::query()->where('organization_id', $request->user()->organization_id)->whereKey($request->input('user_id'))->exists(), 422, 'No such person.');
        }
    }

    private function shown(SchedulingTechnician $t): array
    {
        return [
            'id' => $t->id,
            'franchise_id' => $t->franchise_id,
            'name' => $t->name,
            'email' => $t->email,
            'phone' => $t->phone,
            'is_field_tech' => $t->is_field_tech,
            'is_active' => $t->is_active,
            'user' => $t->user === null ? null : ['id' => $t->user->id, 'name' => $t->user->name, 'email' => $t->user->email],
            'areas' => $t->areas->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
            // Whether Workiz has this person, so a mirror can name them there.
            'in_workiz' => $t->workiz_member_id !== null,
        ];
    }
}
