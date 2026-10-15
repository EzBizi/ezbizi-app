<?php

namespace App\Console\Commands;

use App\Support\Reports\Experimental\RingCentralCallLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Reads yesterday's RingCentral call log into the cache the Experimental report
 * uses, so a week or a month opens from cache instead of waiting on
 * RingCentral's ten-requests-a-minute limit. `--days=31` fills a month at once.
 *
 * Dependencies are taken in handle(), not the constructor: a command's
 * constructor runs at every boot.
 */
class WarmCallLog extends Command
{
    protected $signature = 'ezbizi:warm-call-log {--days=1 : finished days to read, counting back from yesterday}';

    protected $description = 'Cache finished days of the RingCentral call log for the Experimental report';

    public function handle(RingCentralCallLog $calls): int
    {
        if (! $calls->configured()) {
            $this->info('RingCentral is not configured; nothing to read.');

            return self::SUCCESS;
        }

        // The report's own zone when the screen sends none - see the controller.
        $zone = (string) config('app.timezone');
        $today = CarbonImmutable::now($zone)->startOfDay();
        $read = 0;

        for ($back = 1; $back <= max(1, (int) $this->option('days')); $back++) {
            $day = $today->subDays($back);

            if (Cache::has($calls->dayKeyFor($day))) {
                continue;
            }

            $calls->forPeriod($day, $day->endOfDay());
            $read++;
        }

        $this->info("Read {$read} day(s) from RingCentral.");

        return self::SUCCESS;
    }
}
