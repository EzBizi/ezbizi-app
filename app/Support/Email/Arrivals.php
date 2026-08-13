<?php

namespace App\Support\Email;

/**
 * What has reached a shared inbox since a mark — and where the mark is now.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE MARK IS A POSITION, NEVER A COPY.
 *
 *  This module holds no messages and there is no messages table; the
 *  owner's decision is recorded at length on {@see MailboxReader}.
 *  So a watcher cannot answer "what is new?" by comparing what it
 *  fetched against what it stored — there is nothing stored to
 *  compare against, and building it would be the sync engine that was
 *  ruled out.
 *
 *  What is stored instead is one short string per mailbox saying
 *  WHERE THE READING GOT TO. Gmail calls it a history id; IMAP has
 *  UIDVALIDITY and UID. Both are the provider's own answer to "what
 *  has happened since", which is why they are used rather than
 *  subjects or dates — a subject repeats, a Date: header is written
 *  by the sender's computer and is routinely wrong, and neither is
 *  an ordering anybody can resume from.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── `markWasStale` is the answer to the second-worst bug here ────
 *
 * A mark stops being usable. An IMAP mailbox deleted and recreated gets a new
 * UIDVALIDITY and its UIDs start again from 1; a Gmail history id ages out and
 * the API answers 404. The obvious recovery — start again from the beginning —
 * announces the entire inbox as though it had all just arrived, which is the
 * same catastrophe as a first poll that announces everything, wearing a
 * disguise.
 *
 * So the readers detect it themselves, because the rule for when a mark expires
 * belongs with the provider that issued it, and they answer with NO MESSAGES, a
 * FRESH MARK, and this flag set. The watcher's only job is to write the new mark
 * down and say in the log that a gap was skipped. Nothing is announced, because
 * nothing here knows which of those messages were already there.
 */
final class Arrivals
{
    /**
     * @param  MessageSummary[]  $messages  oldest FIRST — see below
     */
    public function __construct(
        /*
         * Oldest first, which is the opposite of every other list in this
         * module.
         *
         * {@see MessagePage} is newest-first because it is drawn on a screen and
         * a mail list with the oldest message at the top reads as broken. This is
         * not drawn anywhere: it is announced, one automation event per message,
         * and the mark advances past each one in turn.
         *
         * Both of those want the same order and it is this one. Events arrive in
         * the order the messages did, so a rule that files them builds a thread
         * the right way round; and a page that is cut short — see `$limit` on
         * {@see MailboxReader::arrivalsSince} — leaves a mark the next poll can
         * resume from, which is only true if what was announced is a PREFIX of
         * what arrived. Newest-first would make a truncated batch announce the
         * newest few and then never reach the rest.
         */
        public readonly array $messages,

        /**
         * Where reading got to — what to store and hand back next time.
         *
         * Always the provider's own value, never derived by counting. It is set
         * even when nothing arrived, because a provider may move its own
         * position without any mail (Gmail's history id advances when a label
         * changes), and holding the old one would make every poll re-ask a
         * question that has already been answered.
         */
        public readonly string $mark,

        /**
         * The mark handed in was no longer usable, so this is a fresh start.
         *
         * When this is true `messages` is ALWAYS empty, and that is not a
         * coincidence to be relied on quietly — it is the requirement. See the
         * class note.
         */
        public readonly bool $markWasStale = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }
}
