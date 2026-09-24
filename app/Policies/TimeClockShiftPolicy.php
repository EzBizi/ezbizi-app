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
        // The Work Hours module since 2026-09-24; it was `hr.view` while the
        // pane was a tab inside Human Resources.
        return $user->hasPermission('work_hours.view')
            ? Response::allow()
            : Refusal::permission('view clock in and out times');
    }

    /**
     * Edit or delete somebody's shift (2026-09-23).
     *
     * `work_hours.manage` (was `hr.hours.correct`), not `hr.edit`: these are the hours a person is paid
     * for, and rewriting them is a bigger authority than keeping their row —
     * the same split the module already makes for `security.reset`.
     */
    public function correct(User $user): Response
    {
        return $user->hasPermission('work_hours.manage')
            ? Response::allow()
            : Refusal::permission('correct clock in and out times');
    }
}
