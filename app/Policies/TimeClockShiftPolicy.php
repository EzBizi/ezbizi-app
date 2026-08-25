<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may read the Clock in–out day list (design §4, §9).
 *
 * The list is an HR surface: `hr.view` is the tab, and a technician who
 * punches (`time_clock.view`) must not see it. Punches themselves are
 * authorized in TimeClock, not here — clock-out stays after the grant is
 * taken away, which a policy on the row would get wrong.
 */
class TimeClockShiftPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('hr.view')
            ? Response::allow()
            : Refusal::permission('view clock in and out times');
    }
}
