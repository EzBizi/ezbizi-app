<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily: exchange each organization's Thumbtack grant so it is never idle
 * long enough to be refused (see ThumbtackClient::keepAlive). One exchange per
 * organization - a single login's grant is shared by all its business rows,
 * and the rotation is written to every row that held it.
 */
class ThumbtackKeepAlive extends Command
{
    protected $signature = 'ezbizi:thumbtack-keep-alive';

    protected $description = 'Refresh each Thumbtack grant so it never goes idle';

    public function handle(TenantContext $tenant): int
    {
        $failed = 0;

        $rows = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Thumbtack->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->get()
            // One refresh per grant, not per organization (audit 11-27: a second
            // login in the same organization went idle and stopped delivering).
            ->unique(fn (SocialConnection $c) => (string) (((array) $c->credentials)['refresh_token'] ?? $c->getKey()));

        foreach ($rows as $row) {
            $organization = Organization::query()->withoutGlobalScopes()->find($row->organization_id);
            if ($organization === null) {
                continue;
            }

            try {
                $tenant->run($organization, fn () => (new ThumbtackClient($row))->keepAlive());
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $this->info('Refreshed '.($rows->count() - $failed).", failed {$failed}.");

        return self::SUCCESS;
    }
}
