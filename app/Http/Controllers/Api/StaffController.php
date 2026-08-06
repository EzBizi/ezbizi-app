<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\PlatformModuleFlag;
use App\Models\StaffImpersonation;
use App\Services\Staff\ImpersonationService;
use App\Support\Modules\ModuleAvailability;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The Development tab (plan §2.1, §4.2).
 *
 * Everything here is METADATA — organizations, module flags, impersonation
 * records. No customer records: reaching those needs an impersonation session,
 * which is what `impersonate()` opens and what tenant endpoints then serve.
 */
class StaffController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleAvailability $modules,
        private readonly ImpersonationService $impersonation,
    ) {}

    // ── organizations ────────────────────────────────────────────

    /** GET /api/v1/staff/organizations */
    public function organizations(): JsonResponse
    {
        $organizations = Organization::withCount(['users', 'allCompanies', 'franchises'])
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $o) => [
                'id'              => $o->id,
                'name'            => $o->name,
                'slug'            => $o->slug,
                'franchise_label' => $o->franchise_label,
                'is_active'       => $o->is_active,
                'is_demo'         => $o->is_demo,
                'is_read_only'    => $o->isReadOnly(),
                'trial_ends_at'   => $o->trial_ends_at?->toIso8601String(),
                'counts'          => [
                    'users'      => $o->users_count,
                    'companies'  => $o->all_companies_count,
                    'franchises' => $o->franchises_count,
                ],
                'created_at' => $o->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $organizations]);
    }

    // ── module flags (the global permissions switchboard) ─────────

    /** GET /api/v1/staff/modules */
    public function modules(): JsonResponse
    {
        $flags = PlatformModuleFlag::all()->keyBy('module_key');

        $data = collect($this->registry->all())->map(function (ModuleDefinition $module, string $key) use ($flags) {
            $flag = $flags->get($key);

            return [
                'key'   => $key,
                'label' => $module->label,
                // No row means available — see ModuleAvailability::isAvailable
                // for why this one gate fails open where permissions fail closed.
                'state' => $flag?->state ?? PlatformModuleFlag::ENABLED,
                'is_default'             => $flag === null,
                'reason'                 => $flag?->reason,
                'changed_by'             => $flag?->changed_by,
                'beta_organization_ids'  => $flag?->beta_organization_ids ?? [],
                'permissions'            => $module->permissionKeys(),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /** PATCH /api/v1/staff/modules/{key} */
    public function setModuleState(Request $request, string $key): JsonResponse
    {
        $input = $request->validate([
            'state'                   => ['required', 'string'],
            'reason'                  => ['nullable', 'string', 'max:1000'],
            'beta_organization_ids'   => ['array'],
            'beta_organization_ids.*' => ['uuid', 'exists:organizations,id'],
        ]);

        $staff = $request->user();

        try {
            $flag = $this->modules->setState(
                moduleKey: $key,
                state: $input['state'],
                reason: $input['reason'] ?? null,
                changedBy: "{$staff->name} <{$staff->email}>",
                betaOrganizationIds: $input['beta_organization_ids'] ?? [],
            );
        } catch (InvalidArgumentException $e) {
            // The service already states the rule and why — repeating it here in
            // different words is how two sources of truth start.
            throw ValidationException::withMessages(['state' => $e->getMessage()]);
        }

        return response()->json(['data' => [
            'key'                   => $flag->module_key,
            'state'                 => $flag->state,
            'reason'                => $flag->reason,
            'beta_organization_ids' => $flag->beta_organization_ids ?? [],
        ]]);
    }

    // ── impersonation (§4.2) ─────────────────────────────────────

    /** POST /api/v1/staff/impersonations */
    public function impersonate(Request $request): JsonResponse
    {
        $input = $request->validate([
            'organization_id'  => ['required', 'uuid', 'exists:organizations,id'],
            'reason'           => ['required', 'string', 'min:10', 'max:1000'],
            'minutes'          => ['integer', 'min:1', 'max:120'],
            'client_approved'  => ['boolean'],
            'approved_by_user_id' => ['nullable', 'uuid'],
        ]);

        try {
            $session = $this->impersonation->begin(
                staff: $request->user(),
                organization: Organization::findOrFail($input['organization_id']),
                reason: $input['reason'],
                minutes: $input['minutes'] ?? 30,
                clientApproved: (bool) ($input['client_approved'] ?? false),
                approvedByUserId: $input['approved_by_user_id'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->present($session)], 201);
    }

    /** DELETE /api/v1/staff/impersonations/{impersonation} */
    public function endImpersonation(Request $request, StaffImpersonation $impersonation): JsonResponse
    {
        $staff = $request->user();

        // Only the staff member who opened it may close it. Someone else closing
        // it would write an end time attributed to the wrong person, which is a
        // worse record than none.
        abort_unless($impersonation->staff_id === $staff->getKey(), 403);

        $this->impersonation->end($impersonation, $staff);

        return response()->json(['data' => $this->present($impersonation->fresh())]);
    }

    /**
     * GET /api/v1/staff/impersonations
     *
     * The whole history, not just this staff member's. Any staff member being
     * able to see who entered which client and why is the point — a log only its
     * author can read supervises nobody.
     */
    public function impersonations(Request $request): JsonResponse
    {
        $sessions = StaffImpersonation::with(['staff:id,name,email', 'organization:id,name'])
            ->when($request->query('organization_id'), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($request->boolean('active'), fn ($q) => $q->whereNull('ended_at')->where('expires_at', '>', now()))
            ->latest('started_at')
            ->limit(200)
            ->get()
            ->map(fn (StaffImpersonation $s) => $this->present($s));

        return response()->json(['data' => $sessions]);
    }

    private function present(StaffImpersonation $session): array
    {
        return [
            'id'              => $session->id,
            'organization'    => [
                'id'   => $session->organization_id,
                'name' => $session->organization?->name,
            ],
            'staff'           => [
                'id'    => $session->staff_id,
                'name'  => $session->staff?->name,
                'email' => $session->staff?->email,
            ],
            'reason'          => $session->reason,
            'started_at'      => $session->started_at->toIso8601String(),
            'expires_at'      => $session->expires_at->toIso8601String(),
            'ended_at'        => $session->ended_at?->toIso8601String(),
            'is_active'       => $session->isActive(),
            'client_approved' => $session->wasApproved(),
        ];
    }
}
