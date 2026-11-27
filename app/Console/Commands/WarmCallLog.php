<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Keeps the finished days of every RingCentral account's call log in EzBizi
 * (call_log_days), so a week or a month opens from there instead of waiting on
 * RingCentral's ten-requests-a-minute limit.
 *
 * Hourly over the last month, not once a night over yesterday (CALLS-504,
 * 2026-11-04): at 02:30 UTC the organization's yesterday is still today in New
 * York, and only the first account was read, so the first person to open a
 * range fetched every missing day live, met RingCentral's 429s, and got
 * nginx's 504. A kept day is one lookup, so the hourly pass costs nothing
 * once the month is in.
 *
 * Dependencies are taken in handle(), not the constructor: a command's
 * constructor runs at every boot.
 */
class WarmCallLog extends Command
{
    protected $signature = 'ezbizi:warm-call-log {--days=31 : finished days to keep, counting back from yesterday}';

    protected $description = 'Keep finished days of every RingCentral call log for the reports';

    public function handle(RingCentralCallLog $calls, TenantContext $tenant): int
    {
        // Every organization with an account, and A1-Group for the env one.
        $organizations = Organization::query()->where('slug', RingCentralCallLog::ENV_ORGANIZATION_SLUG)
            ->orWhereIn('id', SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::RingCentral->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)->select('organization_id'))
            ->get();
        $read = 0;

        foreach ($organizations as $organization) {
            $tenant->run($organization, function () use ($calls, $organization, &$read) {
                // The days the report asks for: its organization's own (ReportView::zone,
                // 2026-11-04), or the kept days would be ones nobody opens.
                $zone = (string) (app(SettingsResolver::class)->get('timezone', $organization) ?: config('app.timezone'));
                $today = CarbonImmutable::now($zone)->startOfDay();

                foreach ($calls->accounts() as $account) {
                    $calls->only($account);
                    if (! $calls->configured()) {
                        continue;
                    }

                    for ($back = 1; $back <= max(1, (int) $this->option('days')); $back++) {
                        $day = $today->subDays($back);
                        if ($calls->hasDay($day)) {
                            continue;
                        }

                        // ponytail: a day per 10 s leaves the live readers (today's report,
                        // missed calls, leads) room in the ten-a-minute limit; per-account
                        // pacing if a backfill ever needs to be faster.
                        if ($read > 0) {
                            Sleep::for(10)->seconds();
                        }

                        try {
                            $calls->forPeriod($day, $day->endOfDay());
                        } catch (RuntimeException $e) {
                            // Still limited after its retries: the next hour carries on.
                            report($e);

                            continue 2;
                        }
                        $read++;
                    }
                }

                $calls->only(null);
            });
        }

        $this->info("Read {$read} day(s) from RingCentral.");

        return self::SUCCESS;
    }
}
