<?php

namespace App\Support\Jobs;

use App\Console\Commands\FlagWorkizJobs;
use App\Models\Job;
use App\Models\ServiceArea;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Scheduling\Openings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Every EzBizi booking path asks this before it books (desk + Jose,
 * 2026-11-17: Maryland Wed 8-11 held 13 against 4). A full window, or a
 * Friday start after noon, is refused with the next three open windows.
 * Holders of scheduling.overbook may book anyway with a reason, which goes on
 * the job and to the organization's Field Manager roles.
 */
final class BookingGate
{
    /**
     * @param  string|null  $at  Wall time "Y-m-d H:i[:s]" in the branch's zone; null = unscheduled, nothing to check.
     * @param  bool  $heldHere  The booking already holds a seat in this window (an online-booking hold being approved).
     * @return string|null The override note to write on the job, or null when the window was fine.
     */
    public static function check(?ServiceArea $area, ?string $at, ?User $user, ?string $reason, bool $heldHere = false): ?string
    {
        if ($area === null || blank($at)) {
            return null;
        }
        $when = CarbonImmutable::parse($at);
        $problems = [];
        if ($when->isFriday() && $when->format('H:i') > '12:00') {
            $problems[] = FlagWorkizJobs::FRIDAY_RULE;
        }

        $openings = app(Openings::class);
        if (Openings::hasCapacity($area)) {
            $hhmm = $when->format('H:i');
            $window = collect($openings->days($area, $when->startOfDay(), 1)[0]['windows'])
                ->first(fn ($w) => $hhmm >= $w['start_time'] && $hhmm < $w['end_time']);
            if ($window === null || ($heldHere ? $window['taken'] > $window['capacity'] : $window['remaining'] < 1)) {
                $problems[] = $window === null
                    ? 'No window is open at '.$when->format('D M j, g:i A').' in '.$area->name.'.'
                    : 'The '.$window['start_time'].'-'.$window['end_time'].' window on '.$when->format('D M j').' in '.$area->name
                        .' is full ('.$window['taken'].' of '.$window['capacity'].').';
            }
        }

        if ($problems === []) {
            return null;
        }
        if ($user?->hasPermission('scheduling.overbook') && filled($reason)) {
            return 'Booked over the limit by '.$user->name.': '.trim($reason).' ('.implode(' ', $problems).')';
        }

        throw new HttpResponseException(response()->json([
            'message' => implode(' ', $problems),
            'errors' => ['scheduled_for' => $problems],
            'next_windows' => self::nextWindows($area, $when),
            'can_override' => (bool) $user?->hasPermission('scheduling.overbook'),
        ], 422));
    }

    /** The note on the job, and an alert to the branch's field managers. */
    public static function record(Job $job, string $note): void
    {
        $job->forceFill(['description' => trim($note."\n\n".$job->description)])->saveQuietly();
        $managers = User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereRaw("LOWER(name) LIKE '%field manager%'"))->pluck('id')->all();
        if ($managers !== []) {
            app(NotificationDispatcher::class)->dispatch(title: 'Job '.$job->number.': '.$note, subject: $job, source: 'system', userIds: $managers);
        }
    }

    /** @return list<array{date: string, start_time: string, end_time: string, remaining: int}> */
    private static function nextWindows(ServiceArea $area, CarbonImmutable $from): array
    {
        $out = [];
        foreach (app(Openings::class)->days($area, $from->startOfDay(), 60) as $day) {
            foreach ($day['windows'] as $w) {
                $friday = CarbonImmutable::parse($day['date'])->isFriday() && $w['start_time'] > '12:00';
                if ($w['remaining'] > 0 && ! $friday && "{$day['date']} {$w['end_time']}" > $from->format('Y-m-d H:i')) {
                    $out[] = ['date' => $day['date'], 'start_time' => $w['start_time'], 'end_time' => $w['end_time'], 'remaining' => $w['remaining']];
                    if (count($out) === 3) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }
}
