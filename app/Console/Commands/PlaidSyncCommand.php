<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Financials\Plaid\PlaidRefused;
use App\Support\Financials\Plaid\PlaidSync;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Plaid → Financials › Bank: each connected bank login, one at a time, in its
 * own tenant. Plaid's SYNC_UPDATES_AVAILABLE webhook runs it for one
 * connection; the schedule (every six hours) is the backstop. Read only.
 */
class PlaidSyncCommand extends Command
{
    protected $signature = 'ezbizi:plaid-sync {--connection= : one Plaid connection id}';

    protected $description = 'Read accounts, balances and transactions from each connected bank (Plaid) into Financials';

    public function handle(PlaidSync $sync, TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Plaid->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $failed = false;
        foreach ($connections as $connection) {
            $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
            try {
                $out = $tenant->run($org, fn () => $sync->run($connection));
                $this->line("{$connection->display_name}: {$out['accounts']} accounts, {$out['added']} added, {$out['modified']} changed, {$out['removed']} removed");
            } catch (PlaidRefused $e) {
                $tenant->run($org, fn () => $connection->forceFill([
                    'status' => SocialConnection::STATUS_BROKEN,
                    'status_reason' => $e->getMessage(),
                    'last_checked_at' => now(),
                ])->save());
                $this->error($connection->display_name.': '.$e->getMessage());
                $failed = true;
            } catch (\Throwable $e) {
                report($e);
                $this->error($connection->display_name.': '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
