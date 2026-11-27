<?php

namespace App\Support\Workiz;

use App\Models\Franchise;
use App\Models\WorkizJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The zone a Workiz ACCOUNT writes its wall times in (CreatedDate,
 * LastStatusUpdate), which is not always its branch's: on 11-12 Reports
 * measured Texas, Illinois, Massachusetts and New York writing Central and
 * Everdoors Pacific, on branches set to New York, so their EzBizi jobs were
 * stamped 1-3 hours early.
 *
 * Measured, not configured: a row is copied in a minute or two after Workiz
 * makes it, so the smallest gap between its CreatedDate and when EzBizi copied
 * it is the account's UTC offset. Kept a day, re-measured after.
 */
final class WorkizClock
{
    private const ZONES = ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu'];

    public static function zone(?Franchise $franchise): string
    {
        $fallback = ($franchise?->timezone ?? '') !== '' ? $franchise->timezone : 'America/New_York';

        if ($franchise === null) {
            return $fallback;
        }

        return Cache::remember('workiz.clock.'.$franchise->getKey(), now()->addDay(), fn () => self::measure($franchise->getKey()) ?? $fallback);
    }

    /** The zone whose offset matches the smallest copy lag of the last week's new rows, or null with too few to tell. */
    public static function measure(string $franchiseId): ?string
    {
        $lag = WorkizJob::query()->where('franchise_id', $franchiseId)->where('created_at', '>', now()->subDays(7))->whereNotNull('booked_at')
            ->selectRaw('count(*) n, min(timestampdiff(SECOND, booked_at, created_at)) s')->first();

        if ($lag === null || $lag->n < 10) {
            return null;
        }

        $hours = (int) floor($lag->s / 3600);

        foreach (self::ZONES as $zone) {
            if (-now($zone)->utcOffset() / 60 === $hours) {
                return $zone;
            }
        }

        return null;
    }
}
