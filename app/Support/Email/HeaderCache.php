<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Cache;

/**
 * A short-lived copy of message HEADERS, so opening the inbox is quick.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  EXACTLY WHAT IS CACHED, AND FOR EXACTLY HOW LONG.
 *
 *  CACHED — one page of {@see MessageSummary}: the provider's message
 *  id, the thread id, the subject, the From name and address, the To
 *  addresses, the sent time, the provider's own short snippet, and
 *  two booleans (has attachments, unread at the provider).
 *
 *  FOR — {@see TTL_SECONDS} seconds. Sixty. See below for why that
 *  number and not five minutes.
 *
 *  WHERE — the CACHE STORE. Not a table, not a model, nothing with a
 *  migration. Losing the whole store costs one round trip per open
 *  inbox and nothing else, which is the property that makes this a
 *  cache.
 *
 *  NEVER CACHED — the BODY, in any form: not the HTML, not the text,
 *  not the sanitised blocks. Not attachments, not attachment
 *  contents, not attachment ids. {@see MessageSummary} has no field
 *  that could hold any of it, so this is enforced by the type rather
 *  than by this paragraph.
 *
 *  SEARCH RESULTS ARE NOT CACHED. A search is a question about the
 *  whole mailbox, its answer changes with every message that arrives,
 *  and somebody searching has usually just failed to find something —
 *  which is the worst possible moment to hand them a minute-old
 *  answer. {@see key} is only ever built for the inbox listing.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── This is a cache and NOT a mirror, and the difference is real ──
 *
 * The owner: *"we don't want to store thousands and thousands of emails on our
 * database."* A mirror is a second copy of the mailbox that has to be kept in
 * step: it needs a sync engine, it needs to learn about deletions, it grows
 * without bound, and it is a copy of somebody's customer mail sitting in our
 * database with its own backup and erasure obligations.
 *
 * This is none of that. It holds ONE PAGE, for ONE MINUTE, and it is
 * authoritative about nothing: every read that matters — opening a message,
 * searching, replying — goes to the provider. The three properties that keep it
 * on the right side of the line are worth naming, because each of them is one
 * "small improvement" away from being lost:
 *
 *   1. IT IS NEVER THE SOURCE OF A DECISION. Nothing reads it to decide whether
 *      a message exists, who may see it, or what it says.
 *   2. IT HAS NO WRITE PATH OF ITS OWN. Only a live listing puts anything in it.
 *      There is no job that fills it in the background — a background filler is
 *      a sync engine with a TTL, whatever it is called.
 *   3. IT EXPIRES WITHOUT ANYBODY DOING ANYTHING. No sweep, no command, no
 *      retention policy, because there is no table.
 *
 * ── Why sixty seconds ────────────────────────────────────────────
 *
 * Short enough that "I refreshed and my new message is not there" cannot become
 * a support call — a minute is roughly how long somebody spends deciding a
 * screen is stale — and long enough to cover what the cache actually exists for:
 * a person opening a message, reading it, pressing back, and opening the next
 * one. That round trip happens three or four times a minute and would otherwise
 * be three or four full listings against somebody else's rate limit.
 *
 * ── The cache key carries the organization ───────────────────────
 *
 * The cache store is shared by every tenant on the installation, and the mailbox
 * id is a UUID which is unguessable — but "unguessable" is not the boundary this
 * application uses anywhere else, and a key that carries the organization can be
 * checked by eye. See {@see key}.
 */
final class HeaderCache
{
    /** Sixty seconds. The reasoning is in the class note; the number is here once. */
    public const TTL_SECONDS = 60;

    /**
     * The listing, from the cache when it is fresh.
     *
     * @param  callable(): MessagePage  $live  what to do when it is not
     */
    public function remember(Mailbox $mailbox, ?string $cursor, int $limit, callable $live): MessagePage
    {
        $key    = $this->key($mailbox, $cursor, $limit);
        $cached = Cache::get($key);

        if ($cached instanceof MessagePage) {
            // Rebuilt rather than returned as-is, so `from_cache` is true on the
            // copy the client sees. A screen that cannot tell the difference is
            // a screen nobody can debug.
            return new MessagePage(
                $cached->messages,
                $cached->nextCursor,
                $cached->approximateTotal,
                fromCache: true,
            );
        }

        $page = $live();

        Cache::put($key, $page, self::TTL_SECONDS);

        return $page;
    }

    /**
     * Drop this mailbox's cached pages.
     *
     * Called after a REPLY, because the thing somebody does immediately after
     * answering a message is look at the list again — and a minute-old copy
     * that still shows it as unanswered is exactly the moment the cache would
     * be blamed for a bug that is not there.
     *
     * ── It forgets the first page only, and says so ──────────────
     *
     * There is no tag or prefix delete in the cache contract Laravel guarantees
     * across every store (the file and database stores have no tags), so this
     * cannot sweep every cursor. The first page is the one somebody comes back
     * to; a deeper page stays for up to a minute. Stated rather than papered
     * over: the alternative is `Cache::flush()`, which would throw away every
     * other tenant's cached everything to tidy one mailbox.
     */
    public function forget(Mailbox $mailbox, int $limit = 25): void
    {
        Cache::forget($this->key($mailbox, null, $limit));
    }

    /**
     * Organization, mailbox, cursor, page size.
     *
     * The page size is part of the key because a page of 25 and a page of 50
     * starting at the same cursor are different answers, and serving one for the
     * other would silently lose messages off the end of the list.
     */
    private function key(Mailbox $mailbox, ?string $cursor, int $limit): string
    {
        return sprintf(
            'email:headers:%s:%s:%s:%d',
            $mailbox->organization_id,
            $mailbox->getKey(),
            $cursor === null ? 'first' : substr(hash('sha256', $cursor), 0, 16),
            $limit,
        );
    }
}
