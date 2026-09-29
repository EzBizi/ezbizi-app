<?php

namespace App\Support\Hr;

use App\Models\EmploymentEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The day somebody started — the earliest `started` employment event.
 *
 * The earliest, because that is what PtoPolicy counts from and what a person
 * means by their start date: somebody rehired has two, and the waiting period
 * runs from the first. When there is none, one is written.
 *
 * Deliberately NOT the activation path, which writes a `started` event stamped
 * today and fires `user.activated`: using that to record a date that already
 * happened would fire automations for somebody who has been here for years and
 * still record the wrong day. Shared by the Records tab and the A1 Tools people
 * import, so the two cannot disagree about which row moves.
 */
final class StartDate
{
    /**
     * @return array{changed: bool, before: ?string, after: string}
     */
    public static function record(User $user, string $on): array
    {
        $date = Carbon::parse($on)->toDateString();

        $first = EmploymentEvent::query()
            ->where('user_id', $user->getKey())
            ->where('kind', EmploymentEvent::STARTED)
            ->orderBy('occurred_on')
            ->first();

        $before = $first?->occurred_on?->toDateString();

        if ($before === $date) {
            return ['changed' => false, 'before' => $before, 'after' => $date];
        }

        if ($first !== null) {
            $first->update(['occurred_on' => $date]);
        } else {
            EmploymentEvent::create([
                'organization_id' => $user->organization_id,
                'user_id'         => $user->getKey(),
                'kind'            => EmploymentEvent::STARTED,
                'occurred_on'     => $date,
            ]);
        }

        return ['changed' => true, 'before' => $before, 'after' => $date];
    }
}
