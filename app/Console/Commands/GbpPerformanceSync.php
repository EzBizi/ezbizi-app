<?php

namespace App\Console\Commands;

use App\Models\BusinessLocation;
use App\Models\BusinessLocationMetric;
use App\Models\Organization;
use App\Services\BusinessProfile\BusinessProfileApi;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Google's daily counts for every listed location (2026-10-20). Google fills
 * a day in late and may revise it, so each run re-reads the last --days days
 * and overwrites them. A listing that fails is reported and skipped, never
 * stopping the others. --days=540 is the backfill.
 */
class GbpPerformanceSync extends Command
{
    protected $signature = 'ezbizi:gbp-performance-sync {--days=10}';

    protected $description = 'Read Business Profile daily performance counts into business_location_metrics';

    public function handle(BusinessProfileApi $api, TenantContext $tenant): int
    {
        $to = CarbonImmutable::yesterday();
        $from = $to->subDays(max(1, (int) $this->option('days')) - 1);
        $failed = 0;

        $listed = BusinessLocation::withoutGlobalScopes()->whereNull('not_listed_since')->whereNotNull('connection_id')->get();
        // Google serves no counts for an unverified listing (403), and a demo
        // listing has nothing behind it: skipped and said so, not failed (2026-10-20).
        $locations = $listed->filter(fn (BusinessLocation $l) => $l->verification_state === BusinessLocation::VERIFIED
            && ! str_starts_with((string) $l->remote_name, 'DEMO-'));
        $skipped = $listed->count() - $locations->count();

        foreach ($locations as $location) {
            $organization = Organization::withoutGlobalScopes()->find($location->organization_id);
            if ($organization === null) {
                continue;
            }

            try {
                $tenant->run($organization, function () use ($api, $location, $from, $to) {
                    $rows = [];
                    foreach ($api->performance($location, $from, $to) as $metric => $days) {
                        foreach ($days as $date => $value) {
                            $rows[] = ['organization_id' => $location->organization_id, 'business_location_id' => $location->id,
                                'date' => $date, 'metric' => $metric, 'value' => $value];
                        }
                    }
                    BusinessLocationMetric::query()->upsert($rows, ['business_location_id', 'date', 'metric'], ['value']);
                });
            } catch (Throwable $e) {
                $failed++;
                $this->warn("{$location->title}: {$e->getMessage()}");
            }
        }

        $this->info(($locations->count() - $failed).' listing(s) read, '.$failed.' failed, '.$skipped.' skipped (not verified or demo).');

        return self::SUCCESS;
    }
}
