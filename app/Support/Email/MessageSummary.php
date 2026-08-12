<?php

namespace App\Support\Email;

use Illuminate\Support\Carbon;

/**
 * One line of the message list — a HEADER, and nothing more.
 *
 * ── Why the shape is defined here and not by the provider ────────
 *
 * Because there are two providers and the list screen must not be able to tell
 * which one it is looking at. Gmail answers with `payload.headers` and a
 * `snippet`; an IMAP server answers with an ENVELOPE structure and BODYSTRUCTURE
 * and no snippet at all. A screen written against either would be a screen that
 * breaks when a customer switches, and a screen written against both would
 * contain the difference — which is the thing an interface exists to hold.
 *
 * ── What is deliberately absent ──────────────────────────────────
 *
 * The BODY. This object is what the list draws and what {@see HeaderCache} may
 * hold briefly, and the decision is that bodies are never stored — so a body on
 * this object would be a body in the cache the first time somebody optimised a
 * round trip away. It is not here, so it cannot be.
 *
 * `snippet` is the exception and it is a considered one: it is the first line or
 * so, it is what makes a mail list usable, and it comes from the provider's own
 * summary where there is one. It is cached with the header and it is measured in
 * characters rather than kilobytes.
 */
final class MessageSummary
{
    /**
     * @param  string[]  $to
     * @param  string[]  $seenBy  the NAMES of people who have opened it, already
     *                            resolved — the list screen prints them and has
     *                            no business holding user ids it would then have
     *                            to look up per row.
     */
    public function __construct(
        /** The provider's own id. Opaque here, and the only thing `message_seen` stores. */
        public readonly string $id,

        /** The conversation this belongs to, where the provider has such a notion. */
        public readonly ?string $threadId,

        public readonly string $subject,
        public readonly string $fromName,
        public readonly string $fromAddress,
        public readonly array $to,
        public readonly ?Carbon $sentAt,

        /** The first line or so. Never the body. */
        public readonly string $snippet,

        public readonly bool $hasAttachments = false,

        /** Unread AT THE PROVIDER — a different fact from EzBizi's own seen marks. */
        public readonly bool $unreadAtProvider = false,

        public readonly array $seenBy = [],
    ) {}

    /**
     * @param  string[]  $seenBy
     */
    public function withSeenBy(array $seenBy): self
    {
        return new self(
            $this->id, $this->threadId, $this->subject, $this->fromName, $this->fromAddress,
            $this->to, $this->sentAt, $this->snippet, $this->hasAttachments,
            $this->unreadAtProvider, $seenBy,
        );
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'thread_id'   => $this->threadId,
            'subject'     => $this->subject,
            'from_name'   => $this->fromName,
            'from_address' => $this->fromAddress,
            'to'          => array_values($this->to),
            'sent_at'     => $this->sentAt?->toIso8601String(),
            'snippet'     => $this->snippet,
            'has_attachments' => $this->hasAttachments,
            'unread_at_provider' => $this->unreadAtProvider,

            /*
             * Names, and it is a list rather than a count.
             *
             * "Seen by 3" is the shape somebody reaches for first and it is the
             * wrong one: the question a shared inbox actually asks is "has
             * anybody dealt with this", and the useful answer is WHO. A count
             * also cannot be drawn as "Seen by you", which is the most common
             * case of all.
             */
            'seen_by'     => array_values($this->seenBy),
        ];
    }
}
