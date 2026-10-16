<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Support\Access\Refusal;
use App\Support\Automations\EventEmitter;
use App\Support\Fleet\CannotAssignVehicle;
use App\Support\Fleet\VehicleAssignments;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hand keys to a person in the company subtree, or take them back (spec §6.3).
 *
 * Assign while someone already holds them closes the open row and inserts the
 * new one in one transaction. That raises vehicle.unassigned then vehicle.assigned.
 */
class VehicleAssignmentsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
        private readonly VehicleAssignments $assignments,
    ) {}

    /**
     * An `exists` rule scoped to this organization (same oracle concern as
     * VehiclesController).
     */
    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/vehicles/drivers?company_id= */
    public function drivers(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('fleet_management.assign'),
            403,
            Refusal::permission('assign a vehicle')->message(),
        );

        $input = $request->validate([
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
        ]);

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;

        $drivers = User::query()
            ->where('is_active', true)
            ->with('assignments.assignedCompany')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $company === null
                ? $this->worksInOrganization($u)
                : $u->canActOnCompany($company))
            ->values()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ]);

        return response()->json(['data' => $drivers]);
    }

    /** POST /api/v1/vehicles/{vehicle}/assignments */
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('assign', $vehicle);

        $input = $request->validate([
            'user_id' => ['required', 'uuid', $this->existsInTenant('users')],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $assignee = User::query()
            ->with('assignments.assignedCompany')
            ->findOrFail($input['user_id']);

        try {
            $assignment = $this->assignments->assign(
                $vehicle,
                $assignee,
                $input['note'] ?? null,
            );
        } catch (CannotAssignVehicle $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->status(),
            );
        }

        return response()->json([
            'data' => [
                'assignment' => $this->presentAssignment($assignment),
            ],
        ]);
    }

    /** POST /api/v1/vehicles/{vehicle}/unassign */
    public function unassign(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('assign', $vehicle);

        if ($this->assignments->release($vehicle) === null) {
            return response()->json([
                'message' => 'Nobody has the keys.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'assignment' => null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function presentAssignment(VehicleAssignment $assignment): array
    {
        $assignment->loadMissing('user:id,name');

        return [
            'id' => $assignment->id,
            'user' => $assignment->user_id === null ? null : [
                'id' => $assignment->user_id,
                'name' => $assignment->user?->name,
            ],
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            'released_at' => $assignment->released_at?->toIso8601String(),
            'note' => $assignment->note,
        ];
    }

    private function worksInOrganization(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $user->loadMissing('assignments.assignedCompany', 'organization');

        return $user->canActOnOrganization($user->organization)
            || $user->reachableCompanies()->isNotEmpty()
            || $user->reachableFranchises()->isNotEmpty();
    }
}
