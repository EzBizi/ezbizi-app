<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\MetaLeadForms;
use App\Support\Social\MetaMessages;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Messenger, Instagram and lead-ad enquiries Meta never delivered, recovered
 * from Meta's own history (Jose, 2026-11-05: "recovery should be automatic").
 * Hourly over a few hours' overlap, so a dropped webhook costs an hour at
 * most; a reconnect runs the same over a week (SubscribeMetaMessages).
 */
class MetaCatchUp extends Command
{
    protected $signature = 'ezbizi:meta-catch-up {--hours=3}';

    protected $description = 'Recover Messenger, Instagram and lead-ad enquiries Meta did not deliver';

    public function handle(TenantContext $tenant, MetaMessages $messages, MetaLeadForms $forms): int
    {
        $since = Carbon::now()->subHours((int) $this->option('hours'));

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->whereIn('provider', [SocialProvider::FacebookPage->value, SocialProvider::InstagramProfessional->value])
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->get();

        foreach ($connections as $connection) {
            try {
                $tenant->run(Organization::findOrFail($connection->organization_id), function () use ($connection, $messages, $forms, $since) {
                    $recovered = $messages->catchUp($connection, $since)
                        + ($connection->provider === SocialProvider::FacebookPage->value ? $forms->catchUp($connection, $since) : 0);

                    $this->line("{$connection->display_name}: {$recovered} recovered");
                });
            } catch (Throwable $e) {
                Log::warning('Meta catch-up failed for one connection.', [
                    'connection_id' => $connection->getKey(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
