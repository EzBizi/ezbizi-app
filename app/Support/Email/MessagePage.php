<?php

namespace App\Support\Email;

/**
 * A page of the message list, with the provider's own cursor.
 *
 * ── A cursor, not an offset ──────────────────────────────────────
 *
 * `?page=3` is meaningless against a live mailbox: two messages arrive while
 * somebody is reading page one, and page two now starts two rows earlier — so
 * they see two messages twice and miss none, or the reverse, depending on the
 * sort. Both providers hand back a continuation token of their own (Gmail's
 * `nextPageToken`, an IMAP sequence position) and it is passed through opaquely
 * rather than translated, because a cursor this application invented would have
 * to be kept in step with a mailbox it does not store.
 *
 * This is also the shape decision 2 forces. Local paging needs a local index;
 * there isn't one, and building one is the sync engine the owner ruled out.
 */
final class MessagePage
{
    /**
     * @param  MessageSummary[]  $messages
     */
    public function __construct(
        public readonly array $messages,

        /** Opaque. Null means this is the end. */
        public readonly ?string $nextCursor = null,

        /**
         * Roughly how many are in the mailbox or the search, where the provider
         * says. Null where it does not, which is honest: an estimate presented
         * as a total is how a list ends up saying "1–25 of 300" over a mailbox
         * with 41 messages in it.
         */
        public readonly ?int $approximateTotal = null,

        /**
         * Where these came from — the provider, or the header cache.
         *
         * Published rather than kept internal, so a screen can show a "just
         * refreshed" state honestly and so somebody debugging a stale list can
         * see which it was without adding logging.
         */
        public readonly bool $fromCache = false,
    ) {}

    public function toArray(): array
    {
        return [
            'messages'    => array_map(fn (MessageSummary $m) => $m->toArray(), $this->messages),
            'next_cursor' => $this->nextCursor,
            'approximate_total' => $this->approximateTotal,
            'from_cache'  => $this->fromCache,
        ];
    }
}
