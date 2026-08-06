<?php

namespace App\Console\Commands;

use App\Services\Staff\ImpersonationService;
use Illuminate\Console\Command;

/**
 * Close impersonation sessions that ran past their expiry (plan §4.2).
 *
 * Access already stops on its own — StaffImpersonation::isActive() re-checks the
 * expiry on every request. What this fixes is the RECORD. A row with no
 * `ended_at` is ambiguous between "still open" and "nobody closed it", and those
 * are very different answers to give a client asking who was in their data.
 */
class ExpireImpersonations extends Command
{
    protected $signature = 'ezbizi:expire-impersonations';

    protected $description = 'Close and record impersonation sessions past their expiry';

    public function handle(ImpersonationService $impersonations): int
    {
        $closed = $impersonations->expireStale();

        $this->info($closed === 0
            ? 'No impersonation sessions were past their expiry.'
            : "Closed {$closed} expired impersonation session(s).");

        return self::SUCCESS;
    }
}
