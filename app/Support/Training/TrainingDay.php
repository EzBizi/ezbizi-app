<?php

namespace App\Support\Training;

use App\Support\Time\ViewTimezone;

/**
 * Today, as a date, in the signed-in person's view timezone (UTC when nobody
 * is signed in, e.g. the nightly sweep). Overdue and "lapsed" are calendar
 * days; on the UTC day an evening in the Americas is already tomorrow.
 */
final class TrainingDay
{
    public static function today(): string
    {
        $user = auth()->user();

        return ($user !== null ? now(ViewTimezone::forUser($user)) : now())->toDateString();
    }
}
