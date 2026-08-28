<?php

namespace App\Policies;

use App\Models\PhoneConnection;
use App\Models\PhoneHoursSchedule;
use App\Models\User;
use App\Policies\Concerns\ScopedToPhoneConnection;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may list or edit named open-hours schedules (spec §12, Task 7).
 *
 * Settings work: phone.edit plus reach on the schedule's connection. View is
 * phone.view narrowed to connections in reach.
 */
class PhoneHoursSchedulePolicy
{
    use ScopedToPhoneConnection;

    public function viewAny(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('open Phone');
    }

    public function view(User $user, PhoneHoursSchedule $schedule): Response
    {
        if ($schedule->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.view')) {
            return Refusal::permission('open Phone');
        }

        return $this->connectionReach($user, $schedule->connection, 'see hours for');
    }

    public function create(User $user, ?PhoneConnection $connection = null): Response
    {
        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('edit phone hours');
        }

        if ($connection === null) {
            return Response::denyAsNotFound();
        }

        return $this->connectionReach($user, $connection, 'edit hours for');
    }

    public function update(User $user, PhoneHoursSchedule $schedule): Response
    {
        if ($schedule->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('edit phone hours');
        }

        return $this->connectionReach($user, $schedule->connection, 'edit hours for');
    }

    public function delete(User $user, PhoneHoursSchedule $schedule): Response
    {
        if ($schedule->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('delete phone hours');
        }

        return $this->connectionReach($user, $schedule->connection, 'delete hours for');
    }
}
