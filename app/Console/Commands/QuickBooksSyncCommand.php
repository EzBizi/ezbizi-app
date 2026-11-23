<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Financials\QuickBooks\QuickBooksRefused;
use App\Support\Financials\QuickBooks\QuickBooksSync;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * QuickBooks Online → Financials › Markets (every 6 hours; --full nightly
 * and on connect). Each connected QuickBooks company, one at a time, in its
 * own tenant. Read only: nothing is written to QuickBooks.
 */
class QuickBooksSyncCommand extends Command
{
    protected $signature = 'ezbizi:quickbooks-sync {--connection= : one QuickBooks connection id} {--full : re-read '.QuickBooksSync::FULL_DAYS.' days by date and drop deleted transactions}';

    protected $description = 'Read expense lines from each connected QuickBooks company into Financials';

    public function handle(QuickBooksSync $sync, TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::QuickBooks->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $failed = false;
        foreach ($connections as $connection) {
            $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
            try {
                $out = $tenant->run($org, fn () => $sync->run($connection, full: (bool) $this->option('full')));
                $this->line("{$connection->display_name}: {$out['transactions']} transactions, {$out['lines']} expense lines, {$out['deleted']} removed");
            } catch (QuickBooksRefused $e) {
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
