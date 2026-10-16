<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\PlatformModuleFlag;
use App\Models\StaffImpersonation;
use App\Services\Staff\ImpersonationService;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\ModuleAvailability;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

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
        private readonly AuditLogger $audit,
    ) {}

    // ── organizations ────────────────────────────────────────────

    /** GET /api/v1/staff/organizations */
    public function organizations(): JsonResponse
    {
        $organizations = Organization::withCount(['users', 'allCompanies', 'franchises'])
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'franchise_label' => $o->franchise_label,
                'is_active' => $o->is_active,
                'is_demo' => $o->is_demo,
                'is_read_only' => $o->isReadOnly(),
                'trial_ends_at' => $o->trial_ends_at?->toIso8601String(),
                'counts' => [
                    'users' => $o->users_count,
                    'companies' => $o->all_companies_count,
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
                'key' => $key,
                'label' => $module->label,
                // No row means available — see ModuleAvailability::isAvailable
                // for why this one gate fails open where permissions fail closed.
                'state' => $flag?->state ?? PlatformModuleFlag::ENABLED,
                'is_default' => $flag === null,
                'reason' => $flag?->reason,
                'changed_by' => $flag?->changed_by,
                'beta_organization_ids' => $flag?->beta_organization_ids ?? [],
                'permissions' => $module->permissionKeys(),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /** PATCH /api/v1/staff/modules/{key} */
    public function setModuleState(Request $request, string $key): JsonResponse
    {
        $input = $request->validate([
            'state' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'beta_organization_ids' => ['array'],
            'beta_organization_ids.*' => ['uuid', 'exists:organizations,id'],
        ]);

        $staff = $request->user();

        $before = PlatformModuleFlag::where('module_key', $key)->first()?->state
            ?? PlatformModuleFlag::ENABLED;

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

        /*
         * Always recorded, including for the Lead Developer.
         *
         * This is the one staff action that changes what EVERY customer sees, at
         * the same moment. `changed_by` on the flag row is overwritten by the
         * next change, so without this an outage is unreconstructable: nothing
         * would say the module was ever off, for how long, or who turned it off.
         *
         * Both tiers may do it — a kill switch is only useful to whoever is awake
         * during the incident (§4.2, "platform work"). The audit row is what makes
         * that safe rather than the tier gate.
         */
        $this->audit->logStaff(
            action: 'staff.module_state_changed',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            metadata: [
                'module' => $key,
                'before' => $before,
                'after' => $flag->state,
                'reason' => $flag->reason,
                'beta_organization_ids' => $flag->beta_organization_ids ?? [],
            ],
        );

        return response()->json(['data' => [
            'key' => $flag->module_key,
            'state' => $flag->state,
            'reason' => $flag->reason,
            'beta_organization_ids' => $flag->beta_organization_ids ?? [],
        ]]);
    }

    // ── impersonation (§4.2) ─────────────────────────────────────

    /** POST /api/v1/staff/impersonations */
    public function impersonate(Request $request): JsonResponse
    {
        $input = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'minutes' => ['integer', 'min:1', 'max:120'],
        ]);

        try {
            // client_approved is NOT taken from the request. A Developer used
            // to POST `client_approved: true` and that was the whole grant.
            // Lead Developer may still enter; that is a property of the
            // account (mayEnterWithoutApproval), not of the body.
            $session = $this->impersonation->begin(
                staff: $request->user(),
                organization: Organization::findOrFail($input['organization_id']),
                reason: $input['reason'],
                minutes: $input['minutes'] ?? 30,
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
        //
        // Said out loud rather than left as a bare 403 rendering "This action is
        // unauthorized." (R8). The reader here is a colleague looking at a
        // shared list — every staff member sees every session, which is the
        // point — so the useful answer is who can end it and what happens if
        // nobody does.
        abort_unless(
            $impersonation->staff_id === $staff->getKey(),
            Response::HTTP_FORBIDDEN,
            'Only the staff member who opened an impersonation can close it, '
            .'because the end time is recorded against whoever closes it. Ask them '
            .'to, or leave it — it expires on its own.',
        );

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
            'id' => $session->id,
            'organization' => [
                'id' => $session->organization_id,
                'name' => $session->organization?->name,
            ],
            'staff' => [
                'id' => $session->staff_id,
                'name' => $session->staff?->name,
                'email' => $session->staff?->email,
            ],
            'reason' => $session->reason,
            'started_at' => $session->started_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'is_active' => $session->isActive(),
            'client_approved' => $session->wasApproved(),
        ];
    }
}
