<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;

/**
 * A slow-lane job fires when a due time has passed since it last fired, not
 * only when a run happens to START in its due minute (desk 11-19: the slow
 * lane's runs start every ~3.5 min, so an everyThirtyMinutes parity hit :00
 * or :30 by chance and did not run from 16:56 to 22:28).
 */
final class DueSinceLastRun
{
    /** The first look at a job only remembers a due time older than this; it does not fire it (a nightly job is not run at deploy time). */
    private const FIRST_LOOK_GRACE_MINUTES = 30;

    /** Tests of lane membership (not timing) set this to true. */
    public static ?bool $fake = null;

    public static function check(string $key, string $expression, ?string $timezone, CarbonImmutable $now): bool
    {
        if (self::$fake !== null) {
            return self::$fake;
        }
        $local = $now->setTimezone($timezone ?: config('app.timezone'));
        $due = CarbonImmutable::instance((new CronExpression($expression))->getPreviousRunDate($local->toDateTime(), 0, true, $local->getTimezone()->getName()));
        $cacheKey = 'slow-due:'.sha1($key);
        $last = Cache::get($cacheKey);
        if ($last !== null && $due->getTimestamp() <= (int) $last) {
            return false;
        }
        Cache::forever($cacheKey, $due->getTimestamp());

        return $last !== null || $due->greaterThanOrEqualTo($local->subMinutes(self::FIRST_LOOK_GRACE_MINUTES));
    }
}
