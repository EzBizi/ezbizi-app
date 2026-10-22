<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Unread per inbox for the inbox list, never waiting on the provider.
 *
 * The last count is served from the same cache the dashboard's email box keeps
 * (`dashboard.email.unread.{id}` = {n, at}); one missing or older than
 * FRESH_SECONDS is re-asked AFTER the response. Null means not counted yet --
 * the list does not wait on 35 inboxes to show the first one.
 */
final class UnreadCounts
{
    private const FRESH_SECONDS = 120;

    public function __construct(private readonly MailboxReaders $readers) {}

    public function cached(Mailbox $mailbox, Organization $organization): ?int
    {
        $key = 'dashboard.email.unread.'.$mailbox->getKey();
        $hit = Cache::get($key);
        $has = is_array($hit) && isset($hit['n'], $hit['at']);

        // phpunit never asks a provider from here: a fake IMAP host would be
        // dialled after the response and mark the inbox broken, as
        // GoogleWorkspace::refreshStatus also guards.
        if (! app()->environment('testing') && (! $has || $hit['at'] < now()->getTimestamp() - self::FRESH_SECONDS) && Cache::add($key.'.refreshing', 1, 60)) {
            dispatch(function () use ($organization, $mailbox, $key) {
                try {
                    app(TenantContext::class)->run($organization, fn () => Cache::forever($key, [
                        'n'  => $this->readers->for($mailbox)->unreadCount($mailbox),
                        'at' => now()->getTimestamp(),
                    ]));
                } catch (Throwable) {
                    // Keep the last count; the next load tries again.
                } finally {
                    Cache::forget($key.'.refreshing');
                }
            })->afterResponse();
        }

        return $has ? (int) $hit['n'] : null;
    }
}
