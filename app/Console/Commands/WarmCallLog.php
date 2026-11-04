<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

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

    protected $description = 'Keep finished days of the RingCentral call log for the Experimental report';

    public function handle(RingCentralCallLog $calls): int
    {
        if (! $calls->configured()) {
            $this->info('RingCentral is not configured; nothing to read.');

            return self::SUCCESS;
        }

        // The days the report asks for: its organization's own (ReportView::zone,
        // 2026-11-04), or the warmed days would be ones nobody opens.
        $org = Organization::query()->where('slug', RingCentralCallLog::ENV_ORGANIZATION_SLUG)->first();
        $zone = (string) (($org === null ? null : app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->get('timezone', $org)))
            ?: config('app.timezone'));
        $today = CarbonImmutable::now($zone)->startOfDay();
        $read = 0;

        for ($back = 1; $back <= max(1, (int) $this->option('days')); $back++) {
            $day = $today->subDays($back);

            if ($calls->hasDay($day)) {
                continue;
            }

            $calls->forPeriod($day, $day->endOfDay());
            $read++;
        }

        $this->info("Read {$read} day(s) from RingCentral.");

        return self::SUCCESS;
    }
}
