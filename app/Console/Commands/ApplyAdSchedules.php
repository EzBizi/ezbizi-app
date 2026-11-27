<?php

namespace App\Console\Commands;

use App\Models\AdSchedule;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Ads\CampaignPerformance;
use App\Support\Audit\AuditLogger;
use App\Support\Social\MetaAdsManager;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Turns scheduled campaigns on and off (Jose, 2026-11-05: "turn it on/off at
 * designated times"). Edge-triggered: a schedule acts only when the state it
 * wants differs from the last one it applied, so a person's manual flip
 * between two edges stands until the next edge. The change goes through the
 * same call the row's switch makes, and is audited the same way.
 */
class ApplyAdSchedules extends Command
{
    protected $signature = 'ezbizi:ad-schedules';

    protected $description = 'Turn scheduled ad campaigns on and off at their set times';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $now = Carbon::now();

        foreach (AdSchedule::query()->withoutGlobalScopes()->get() as $schedule) {
            $want = $schedule->wantsOn($now) ? AdSchedule::ON : AdSchedule::OFF;

            // ponytail: a refused change is asked again every 15 minutes, not every minute.
            if ($schedule->last_state === $want
                || ($schedule->last_error !== null && $schedule->updated_at?->gt($now->copy()->subMinutes(15)))) {
                continue;
            }

            $tenant->run(Organization::findOrFail($schedule->organization_id), function () use ($schedule, $want, $audit) {
                $connection = SocialConnection::query()->whereKey($schedule->connection_id)
                    ->where('status', SocialConnection::STATUS_CONNECTED)->first();

                try {
                    if ($connection === null) {
                        throw new RuntimeException('The ad account is not connected any more. Connect it again in Settings › Integrations.');
                    }

                    if ($connection->provider === SocialProvider::GoogleAds->value) {
                        app(CampaignPerformance::class)->setStatus($connection, $schedule->object_id, $want === AdSchedule::ON ? 'ENABLED' : 'PAUSED');
                        $audit->log('ads_performance.campaign_status_changed', $connection, ['status' => $want === AdSchedule::ON ? 'ENABLED' : 'PAUSED'],
                            ['campaign_id' => $schedule->object_id, 'customer_id' => $connection->remote_id, 'by' => 'schedule', 'schedule_id' => $schedule->id]);
                    } else {
                        app(MetaAdsManager::class)->setStatus($connection, $schedule->object_id, $want === AdSchedule::ON ? 'ACTIVE' : 'PAUSED');
                        $audit->log('meta_ads.status_changed', $connection, ['status' => $want === AdSchedule::ON ? 'ACTIVE' : 'PAUSED'],
                            ['object_id' => $schedule->object_id, 'account_id' => $connection->remote_id, 'by' => 'schedule', 'schedule_id' => $schedule->id]);
                    }
                } catch (RuntimeException $e) {
                    // Kept on the row and tried again next minute; Meta's own words when it refused.
                    $schedule->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

                    return;
                }

                $schedule->forceFill(['last_state' => $want, 'last_applied_at' => now(), 'last_error' => null])->save();
                $this->line("{$schedule->object_name}: {$want}");
            });
        }

        return self::SUCCESS;
    }
}
