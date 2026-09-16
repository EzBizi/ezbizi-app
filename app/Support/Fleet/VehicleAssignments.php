<?php

namespace App\Support\Fleet;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;

/**
 * Who holds the keys to a vehicle (spec §6.3) — the one implementation of it.
 *
 * Extracted from VehicleAssignmentsController on 2026-09-16 because the
 * Automations session needed to cause an assignment from a rule and declined
 * to copy the controller's twenty-five lines into ActionRunner. They were
 * right to decline: four of the six steps here are decisions about the fleet
 * domain — who is in reach, that reassigning RELEASES rather than overwrites,
 * that both halves are one transaction, and which events say so — and a second
 * copy would have drifted the first time one changed, silently, because
 * nothing would have failed.
 *
 * So the rule of this class: every path that hands over or takes back keys
 * goes through `assign` or `release`. A caller decides WHETHER it may act
 * (the policy, `fleet_management.assign`) and this decides what acting means.
 */
class VehicleAssignments
{
    public function __construct(private readonly EventEmitter $events) {}

    /**
     * Can this person be handed these keys?
     *
     * A vehicle with no company belongs to the whole group, so the test widens
     * to the organization; otherwise the person must be able to act on the
     * vehicle's own company. Inactive people are out either way — a leaver
     * still reachable through a stale assignment must not receive a van.
     */
    public function reaches(Vehicle $vehicle, User $assignee): bool
    {
        if (! $assignee->is_active) {
            return false;
        }

        $assignee->loadMissing('assignments.assignedCompany', 'organization');

        if ($vehicle->company_id === null) {
            return $assignee->canActOnOrganization($assignee->organization);
        }

        return $assignee->canActOnCompany($vehicle->company);
    }

    /**
     * Why they cannot, in the words shown on screen and in a rule's run log.
     */
    public function outOfReachMessage(Vehicle $vehicle): string
    {
        return $vehicle->company_id === null
            ? 'You can assign people who work in this group. This person does not.'
            : 'You can assign people who work in this company. This person does not.';
    }

    /**
     * Hand the keys over, releasing whoever held them.
     *
     * Reassignment is a release plus a new row, never an edit of the old one:
     * "who had this van in March" is the question the ledger exists to answer,
     * and overwriting `user_id` would destroy it.
     *
     * @throws CannotAssignVehicle when the person is not in the vehicle's reach
     */
    public function assign(
        Vehicle $vehicle,
        User $assignee,
        ?string $note = null,
    ): VehicleAssignment {
        if (! $this->reaches($vehicle, $assignee)) {
            throw new CannotAssignVehicle($this->outOfReachMessage($vehicle));
        }

        return DB::transaction(function () use ($vehicle, $assignee, $note) {
            $this->closeOpen($vehicle);

            $assignment = VehicleAssignment::create([
                'vehicle_id' => $vehicle->getKey(),
                'user_id' => $assignee->getKey(),
                'assigned_at' => now(),
                'released_at' => null,
                'note' => $note,
            ]);

            $this->events->emit('vehicle.assigned', $vehicle, [
                'after' => ['user_id' => $assignee->getKey()],
            ]);

            return $assignment;
        });
    }

    /**
     * Take the keys back. Null when nobody held them.
     *
     * Answering null rather than throwing: a rule that unassigns a van nobody
     * is driving has got what it wanted, and should not read as a failure. The
     * controller turns the null into its own sentence, because a PERSON who
     * pressed Unassign on an empty vehicle does want telling.
     */
    public function release(Vehicle $vehicle): ?VehicleAssignment
    {
        $open = $vehicle->openAssignment();
        if ($open === null) {
            return null;
        }

        return DB::transaction(function () use ($vehicle, $open) {
            $this->closeOpen($vehicle);

            return $open;
        });
    }

    /**
     * Close the open row and say so. Inside the caller's transaction, so the
     * release and whatever follows it cannot land apart.
     */
    private function closeOpen(Vehicle $vehicle): void
    {
        $open = $vehicle->openAssignment();
        if ($open === null) {
            return;
        }

        $open->released_at = now();
        $open->save();

        $this->events->emit('vehicle.unassigned', $vehicle, [
            'before' => ['user_id' => $open->user_id],
            'after' => ['user_id' => null],
        ]);
    }
}
