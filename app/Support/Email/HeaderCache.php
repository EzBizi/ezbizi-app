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
        // Every first-page size the screens ask for, and the warm mark, so the
        // warmer re-reads it on its next minute.
        foreach (array_unique([$limit, ...self::WARM_LIMITS]) as $size) {
            Cache::forget($this->key($mailbox, null, $size));
        }

        Cache::forget($this->markKey($mailbox));
    }

    /** The first-page sizes the inbox screens ask for: one inbox, and All inboxes. */
    public const WARM_LIMITS = [25, 10];

    /** How long a warmed first page is served; the warmer replaces it on any change well before. */
    public const WARM_SECONDS = 900;

    /**
     * Keep this mailbox's first pages ready (ezbizi:warm-inbox-headers).
     *
     * Jose, 2026-10-27: "slow loading times for email inboxes". Measured on
     * production: a first page is 1 list + 1 call per conversation, ~1.1 s for
     * 25, read again on every open and once per inbox on All inboxes. Opening a
     * message was 130-435 ms and is left alone.
     *
     * Re-read only when the provider's position (`currentMark`, one cheap call)
     * moved since the last warm, so a quiet inbox costs one call a minute.
     *
     * @param  callable(int): MessagePage  $live  reads a first page of the given size
     * @return bool  true when the pages were re-read
     */
    public function warm(Mailbox $mailbox, string $mark, callable $live): bool
    {
        $fresh = Cache::get($this->markKey($mailbox)) === $mark;

        foreach (self::WARM_LIMITS as $size) {
            $fresh = $fresh && Cache::has($this->key($mailbox, null, $size));
        }

        if ($fresh) {
            return false;
        }

        foreach (self::WARM_LIMITS as $size) {
            Cache::put($this->key($mailbox, null, $size), $live($size), self::WARM_SECONDS);
        }

        Cache::put($this->markKey($mailbox), $mark, self::WARM_SECONDS);

        return true;
    }

    private function markKey(Mailbox $mailbox): string
    {
        return 'email.headers.warm-mark.'.$mailbox->getKey();
    }

    /**
     * The unread count, for the same {@see TTL_SECONDS} as a page of headers.
     *
     * ── Why this exists, found by the Dashboard session ──────────
     *
     * `EmailGlance` calls `unreadCount()` for EVERY readable mailbox, serially,
     * on every load of the home screen. No reader cached it. So the first
     * screen of the application had its load time bounded by third-party mail
     * servers — mailboxes × the reader's timeout in the worst case, and there
     * is nothing between "answers in 200ms" and "gives up at ten seconds",
     * because a slow host is not an error and the per-mailbox `catch` only
     * helps when something actually fails.
     *
     * The asymmetry is what makes this obviously right rather than a judgement
     * call: this module ALREADY caches the expensive call — a whole page of
     * headers, above — and did not cache the cheap one that runs on a screen
     * everybody opens. Same TTL, same argument, one of them simply never got
     * written.
     *
     * ── Per MAILBOX, not per person, and that is the win ─────────
     *
     * An unread count is a fact about the mailbox. Four colleagues opening the
     * dashboard within a minute share one answer instead of making four
     * identical round trips to the same mail server.
     *
     * ── IF YOU ADD A CALLER, YOU ARE THE ONE WHO CAN BREAK THIS ──
     *
     * A cache keyed on a RESOURCE rather than on a person is only safe while
     * every caller has already decided that this viewer may read this mailbox.
     * The key is organization-prefixed, so a mailbox id cannot collide across
     * tenants — but that is the second line of defence, not the first. The
     * first is that nothing consults this cache for a mailbox the caller was
     * not already entitled to open.
     *
     * `EmailGlance` earns it: it resolves the readable mailboxes and filters
     * them to the current view BEFORE its loop reaches `unreadCount`. So the
     * cache is downstream of the access decision, never upstream of it.
     *
     * That invariant lives across two files and was written in neither until
     * the Dashboard session went looking for it — the safety of this cache is
     * a property of its CALLERS, and this class cannot enforce it. A caller
     * that asked for a count first and checked permission afterwards would
     * still be correct on a cache miss and wrong on a hit, which is the worst
     * shape a leak can have: intermittent, and dependent on who loaded the
     * page a minute earlier.
     *
     * So: filter by access first. If that ever becomes inconvenient, key this
     * per viewer instead — and lose the sharing that is the whole point.
     *
     * ── Sixty seconds is a count, not a truth ────────────────────
     *
     * `EmailGlance` declares `SNAPSHOT = true` — it is explicitly a reading at
     * a moment rather than a windowed figure — so a count that lags a minute is
     * still a count. Nobody acts on the difference between 11 and 12 unread;
     * they act on "there is mail waiting", which a minute does not change.
     */
    public function unread(Mailbox $mailbox, callable $count): int
    {
        $key = sprintf(
            'email:unread:%s:%s',
            $mailbox->organization_id,
            $mailbox->getKey(),
        );

        $cached = Cache::get($key);

        if (is_int($cached)) {
            return $cached;
        }

        $fresh = (int) $count();

        Cache::put($key, $fresh, self::TTL_SECONDS);

        return $fresh;
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
