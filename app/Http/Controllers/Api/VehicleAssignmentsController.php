<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Support\Access\Refusal;
use App\Support\Automations\EventEmitter;
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

        $inReach = $vehicle->company_id === null
            ? $this->worksInOrganization($assignee)
            : ($assignee->is_active && $assignee->canActOnCompany($vehicle->company));

        if (! $inReach) {
            return response()->json([
                'message' => $vehicle->company_id === null
                    ? 'You can assign people who work in this group. This person does not.'
                    : 'You can assign people who work in this company. This person does not.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $assignment = DB::transaction(function () use ($vehicle, $assignee, $input) {
            $open = $vehicle->openAssignment();
            if ($open !== null) {
                $open->released_at = now();
                $open->save();
                $this->events->emit('vehicle.unassigned', $vehicle, [
                    'before' => ['user_id' => $open->user_id],
                    'after' => ['user_id' => null],
                ]);
            }

            $assignment = VehicleAssignment::create([
                'vehicle_id' => $vehicle->getKey(),
                'user_id' => $assignee->getKey(),
                'assigned_at' => now(),
                'released_at' => null,
                'note' => $input['note'] ?? null,
            ]);

            $this->events->emit('vehicle.assigned', $vehicle, [
                'after' => ['user_id' => $assignee->getKey()],
            ]);

            return $assignment;
        });

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

        $open = $vehicle->openAssignment();
        if ($open === null) {
            return response()->json([
                'message' => 'Nobody has the keys.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($vehicle, $open) {
            $open->released_at = now();
            $open->save();
            $this->events->emit('vehicle.unassigned', $vehicle, [
                'before' => ['user_id' => $open->user_id],
                'after' => ['user_id' => null],
            ]);
        });

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
