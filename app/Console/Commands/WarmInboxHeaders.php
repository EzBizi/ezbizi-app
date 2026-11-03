<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\Email\HeaderCache;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps every connected inbox's first page in the header cache, so opening an
 * inbox -- or All inboxes -- reads from cache instead of Gmail (2026-10-27).
 * See HeaderCache::warm for the measurement and the change test.
 */
class WarmInboxHeaders extends Command
{
    private const NEXT_KEY = 'email.headers.warm-next';

    protected $signature = 'ezbizi:warm-inbox-headers {--budget=50 : seconds to spend before stopping}';

    protected $description = 'Keep connected inboxes\' first pages cached, re-reading only inboxes that changed';

    public function handle(TenantContext $tenant, MailboxReaders $readers, HeaderCache $cache): int
    {
        $started = microtime(true);
        $budget  = (int) $this->option('budget');
        $warmed  = 0;

        $mailboxes = $tenant->runWithoutTenant(fn () => Mailbox::query()
            ->where('status', Mailbox::STATUS_CONNECTED)
            ->orderBy('organization_id')->orderBy('id')
            ->get());

        // Start where the last run ran out of budget. A fixed order re-read the
        // same ~24 busy inboxes every minute and the rest went cold (11-02:
        // All inboxes 20s with 28 of 66 first pages live).
        $count = $mailboxes->count();
        $start = $count > 0 ? ((int) Cache::get(self::NEXT_KEY, 0)) % $count : 0;
        $organizations = [];
        $done = 0;

        foreach ($mailboxes->slice($start)->concat($mailboxes->take($start)) as $mailbox) {
            if (microtime(true) - $started > $budget) {
                break;   // the rest next minute
            }

            $organization = $organizations[$mailbox->organization_id] ??= $tenant->runWithoutTenant(
                fn () => Organization::query()->find($mailbox->organization_id),
            );
            $done++;

            if ($organization === null) {
                continue;
            }

            try {
                $warmed += (int) $tenant->run($organization, function () use ($mailbox, $readers, $cache) {
                    $reader = $readers->for($mailbox);

                    return $cache->warm(
                        $mailbox,
                        $reader->currentMark($mailbox),
                        fn (int $size) => $reader->list($mailbox, null, $size, 'INBOX'),
                    );
                });
            } catch (Throwable) {
                // A broken inbox says so on its own card; the screen reads it live.
            }
        }

        Cache::forever(self::NEXT_KEY, $count > 0 ? ($start + $done) % $count : 0);

        $this->info("Re-read {$warmed} of {$mailboxes->count()} inboxes.");

        return self::SUCCESS;
    }
}
