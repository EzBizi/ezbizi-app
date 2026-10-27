<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\Email\HeaderCache;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps every connected inbox's first page in the header cache, so opening an
 * inbox -- or All inboxes -- reads from cache instead of Gmail (2026-10-27).
 * See HeaderCache::warm for the measurement and the change test.
 */
class WarmInboxHeaders extends Command
{
    protected $signature = 'ezbizi:warm-inbox-headers {--budget=50 : seconds to spend before stopping}';

    protected $description = 'Keep connected inboxes\' first pages cached, re-reading only inboxes that changed';

    public function handle(TenantContext $tenant, MailboxReaders $readers, HeaderCache $cache): int
    {
        $started = microtime(true);
        $budget  = (int) $this->option('budget');
        $warmed  = 0;

        $mailboxes = $tenant->runWithoutTenant(fn () => Mailbox::query()
            ->where('status', Mailbox::STATUS_CONNECTED)
            ->orderBy('organization_id')
            ->get());

        foreach ($mailboxes->groupBy('organization_id') as $organizationId => $rows) {
            $organization = $tenant->runWithoutTenant(fn () => Organization::query()->find($organizationId));

            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($rows, $readers, $cache, $started, $budget, &$warmed) {
                foreach ($rows as $mailbox) {
                    if (microtime(true) - $started > $budget) {
                        return;   // the rest next minute
                    }

                    try {
                        $reader = $readers->for($mailbox);
                        $warmed += (int) $cache->warm(
                            $mailbox,
                            $reader->currentMark($mailbox),
                            fn (int $size) => $reader->list($mailbox, null, $size, 'INBOX'),
                        );
                    } catch (Throwable) {
                        // A broken inbox says so on its own card; the screen reads it live.
                    }
                }
            });
        }

        $this->info("Re-read {$warmed} of {$mailboxes->count()} inboxes.");

        return self::SUCCESS;
    }
}
