<?php

namespace App\Console\Commands;

use App\Models\MetaAdInsight;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\MetaAdInsights;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Facebook Ads into Reports. Nightly it re-reads the last few days, which Meta
 * keeps revising; --backfill reads everything Meta still holds (totals 37
 * months, the region breakdown 13). A connection seen for the first time is
 * backfilled without being asked.
 */
class MetaAdsSync extends Command
{
    protected $signature = 'ezbizi:meta-ads-sync {--days=3} {--backfill} {--connection= : one connection only, as right after it connects}';

    protected $description = 'Read Facebook ad campaigns and daily ad-set results into Reports';

    public function handle(TenantContext $tenant, MetaAdInsights $ads): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::FacebookAds->value)
            ->where('status', 'connected')
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($connections as $connection) {
            try {
                $tenant->run(Organization::findOrFail($connection->organization_id), function () use ($connection, $ads) {
                    $today = Carbon::now(\App\Support\Settings\OrgTimezone::of())->startOfDay();
                    $first = ! MetaAdInsight::query()->where('connection_id', $connection->getKey())->exists();
                    $campaigns = $ads->campaigns($connection);

                    if ($first || $this->option('backfill')) {
                        $written = $ads->sync($connection, $today->copy()->subMonths(MetaAdInsights::TOTALS_MONTHS)->addDay(), $today->copy()->subMonths(MetaAdInsights::REGION_MONTHS), false)
                            + $ads->sync($connection, $today->copy()->subMonths(MetaAdInsights::REGION_MONTHS)->addDay(), $today, true);
                    } else {
                        $written = $ads->sync($connection, $today->copy()->subDays((int) $this->option('days')), $today, true);
                    }

                    $this->line("{$connection->display_name}: {$campaigns} campaigns, {$written} rows");
                });
            } catch (Throwable $e) {
                Log::warning('Facebook Ads sync failed for one connection.', [
                    'connection_id' => $connection->getKey(),
                    'reason' => $e->getMessage(),
                ]);
                $this->warn("{$connection->display_name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
