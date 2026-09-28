<?php

namespace App\Policies;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may configure a franchise's capacity
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md).
 *
 * Deliberately NOT AuthorizesCatalogPlace. That trait reaches for a row that
 * may sit on an organization, a company OR a franchise, and answers three
 * different questions depending which. A service area is always a franchise's:
 * `franchise_id` is not nullable, because "where do we work" is a question only
 * a franchise can answer. Reusing the trait would mean writing two branches
 * that can never be taken and one that can, which reads as flexibility and is
 * really just dead code around the only real case.
 */
class ServiceAreaPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('scheduling.view')
            ? Response::allow()
            : Refusal::permission('see when this business works');
    }

    public function view(User $user, ServiceArea $area): Response
    {
        if (! $user->hasPermission('scheduling.view')) {
            return Refusal::permission('see when this business works');
        }

        return $this->within($user, $area, 'see this service area');
    }

    public function create(User $user, Franchise $franchise): Response
    {
        if (! $user->hasPermission('scheduling.create')) {
            return Refusal::permission('add a service area');
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a service area', $franchise->name);
    }

    /** A franchise's daily limit: editing Scheduling, within reach of that franchise. */
    public function limit(User $user, Franchise $franchise): Response
    {
        if (! $user->hasPermission('scheduling.edit')) {
            return Refusal::permission("change a franchise's daily limit");
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, "change a franchise's daily limit", $franchise->name);
    }

    public function update(User $user, ServiceArea $area): Response
    {
        if (! $user->hasPermission('scheduling.edit')) {
            return Refusal::permission('change this service area');
        }

        return $this->within($user, $area, 'change this service area');
    }

    public function delete(User $user, ServiceArea $area): Response
    {
        if (! $user->hasPermission('scheduling.delete')) {
            return Refusal::permission('delete this service area');
        }

        return $this->within($user, $area, 'delete this service area');
    }

    private function within(User $user, ServiceArea $area, string $act): Response
    {
        $franchise = $area->franchise;

        if ($franchise === null) {
            // The FK is constrained, so this is a row whose franchise was
            // removed underneath it rather than a row that never had one.
            return Response::deny('That service area no longer belongs to a franchise.');
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }
}
