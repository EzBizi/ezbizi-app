<?php

namespace App\Console\Commands;

use App\Models\AdsAccountState;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Ads\LsaCertifications;
use App\Support\Automations\EventEmitter;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Every hour: is each Local Services campaign still showing? When one stops -
 * paused, ended, no longer eligible - `ads.campaign_stopped` is raised, naming
 * who paused it when anybody did.
 *
 * A1 Leads does the same (its `ad-status-intent`): a pause somebody planned
 * and a pause nobody can explain look identical in Google, and only the second
 * is an incident. Here the difference is in the payload: a pause pressed in
 * EzBizi names the person from our own audit, one made elsewhere names the
 * Google user from Google's change history, and one with neither is the one
 * to worry about. Read-only: nothing here changes a campaign.
 *
 * The first look at an account raises nothing - it has no "before".
 */
class AdsCampaignWatch extends Command
{
    protected $signature = 'ezbizi:ads-campaign-watch {--connection= : one connection id}';

    protected $description = 'Notice when a Local Services campaign stops showing, and who paused it';

    /** Google's primary status for a campaign that is showing, or could. */
    public const SHOWING = ['ELIGIBLE', 'LIMITED', 'LEARNING', 'PENDING'];

    public function handle(LsaCertifications $google, TenantContext $tenant, EventEmitter $events): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $raised = $failed = 0;

        foreach ($connections->groupBy('organization_id') as $organizationId => $group) {
            $organization = Organization::query()->withoutGlobalScopes()->find($organizationId);
            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($group, $google, $events, &$raised, &$failed) {
                foreach ($group as $connection) {
                    try {
                        $token = GoogleToken::forConnection($connection, 'google_ads');
                        $rows = $google->search($connection, $token,
                            'SELECT campaign.id, campaign.name, campaign.status, campaign.primary_status '
                            ."FROM campaign WHERE campaign.advertising_channel_type = 'LOCAL_SERVICES' "
                            ."AND campaign.status != 'REMOVED'");
                    } catch (RuntimeException) {
                        $failed++;

                        continue;
                    }

                    $now = [];
                    foreach ($rows as $row) {
                        $c = (array) ($row['campaign'] ?? []);
                        $now[(string) ($c['id'] ?? '')] = [
                            'id' => (string) ($c['id'] ?? ''),
                            'name' => (string) ($c['name'] ?? ''),
                            'status' => (string) ($c['status'] ?? ''),
                            'primary_status' => (string) ($c['primaryStatus'] ?? ''),
                        ];
                    }

                    $state = AdsAccountState::query()->firstOrNew(['connection_id' => $connection->getKey()]);
                    $before = $state->campaigns_checked_at === null
                        ? null
                        : collect((array) $state->campaigns)->keyBy('id');
                    $state->fill(['campaigns' => array_values($now), 'campaigns_checked_at' => now()])->save();

                    if ($before === null) {
                        continue;
                    }

                    foreach ($now as $id => $campaign) {
                        $was = $before->get($id);
                        if ($was === null) {
                            continue;
                        }
                        // Showing again: the other half of an account change
                        // alert (A1 Leads takeover, 2026-10-19).
                        if (! self::showing($was) && self::showing($campaign)) {
                            $events->emit('ads.campaign_resumed', $state, [
                                'account' => $connection->label ?: $connection->display_name,
                                'customer_id' => $connection->remote_id,
                                'campaign' => $campaign['name'],
                                'campaign_id' => $id,
                            ]);
                            $raised++;

                            continue;
                        }
                        if (! self::showing($was) || self::showing($campaign)) {
                            continue;
                        }

                        $events->emit('ads.campaign_stopped', $state, [
                            'account' => $connection->label ?: $connection->display_name,
                            'customer_id' => $connection->remote_id,
                            'campaign' => $campaign['name'],
                            'campaign_id' => $id,
                            'status' => $campaign['status'],
                            'reason' => strtolower(str_replace('_', ' ', $campaign['primary_status'])),
                            'paused_by' => $campaign['status'] === 'PAUSED'
                                ? $this->whoPaused($google, $connection, $token, $id)
                                : null,
                        ]);
                        $raised++;
                    }
                }
            });
        }

        $this->info("Watched {$connections->count()} account(s); {$failed} could not be read; {$raised} raised.");

        return self::SUCCESS;
    }

    /** @param array<string, string> $campaign */
    public static function showing(array $campaign): bool
    {
        return $campaign['status'] === 'ENABLED' && in_array($campaign['primary_status'], self::SHOWING, true);
    }

    /**
     * "EzBizi: <person>" when the pause was pressed here, the Google user when
     * Google's change history has it, null when nobody can be named.
     */
    private function whoPaused(LsaCertifications $google, SocialConnection $connection, string $token, string $campaignId): ?string
    {
        $ours = AuditEvent::query()
            ->where('action', 'ads_performance.campaign_status_changed')
            ->where('target_id', $connection->getKey())
            ->where('occurred_at', '>=', now()->subDay())
            ->latest('occurred_at')
            ->get()
            ->first(fn (AuditEvent $e) => (string) ($e->metadata['campaign_id'] ?? '') === $campaignId
                && ($e->changes['status'] ?? null) === 'PAUSED');
        if ($ours !== null) {
            // The label is "Name <email>"; the name is enough.
            return 'EzBizi: '.(trim((string) preg_replace('/\s*<[^>]*>$/', '', (string) $ours->actor_label)) ?: 'a person');
        }

        try {
            $changes = $google->search($connection, $token,
                'SELECT change_event.change_date_time, change_event.user_email, change_event.client_type, change_event.campaign '
                ."FROM change_event WHERE change_event.change_resource_type = 'CAMPAIGN' "
                .'AND change_event.change_date_time DURING LAST_14_DAYS '
                .'ORDER BY change_event.change_date_time DESC LIMIT 50');
        } catch (RuntimeException) {
            return null;
        }

        foreach ($changes as $row) {
            $e = (array) ($row['changeEvent'] ?? []);
            if (str_ends_with((string) ($e['campaign'] ?? ''), '/campaigns/'.$campaignId)) {
                return trim(($e['userEmail'] ?? 'Google').' ('.strtolower(str_replace('_', ' ', (string) ($e['clientType'] ?? ''))).')');
            }
        }

        return null;
    }
}
