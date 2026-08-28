<?php

namespace App\Policies;

use App\Models\PhoneConnection;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Policies\Concerns\ScopedToPhoneConnection;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may list or edit ring groups (spec §12, decision 5).
 *
 * Same shape as hours: Settings work is phone.edit plus reach on the group's
 * connection, and viewing is phone.view narrowed the same way. Who may ANSWER
 * a line is a different question entirely — that is the per-number grant, and
 * being in a ring group does not imply it.
 */
class PhoneRingGroupPolicy
{
    use ScopedToPhoneConnection;

    public function viewAny(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('open Phone');
    }

    public function view(User $user, PhoneRingGroup $group): Response
    {
        if ($group->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.view')) {
            return Refusal::permission('open Phone');
        }

        return $this->connectionReach($user, $group->connection, 'see ring groups for');
    }

    public function create(User $user, ?PhoneConnection $connection = null): Response
    {
        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('edit ring groups');
        }

        if ($connection === null) {
            return Response::denyAsNotFound();
        }

        return $this->connectionReach($user, $connection, 'edit ring groups for');
    }

    public function update(User $user, PhoneRingGroup $group): Response
    {
        if ($group->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('edit ring groups');
        }

        return $this->connectionReach($user, $group->connection, 'edit ring groups for');
    }

    public function delete(User $user, PhoneRingGroup $group): Response
    {
        if ($group->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('delete ring groups');
        }

        return $this->connectionReach($user, $group->connection, 'delete ring groups for');
    }
}
