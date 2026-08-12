<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * Reading a shared inbox — LIVE, from the provider, every time.
 *
 * ── Two implementations, one shape, and neither is finishable here ─
 *
 * {@see GmailMailboxReader} and {@see ImapMailboxReader} both implement this,
 * and NEITHER can be completed without real provider credentials: one needs a
 * registered Google OAuth client and a consent screen, the other needs a mail
 * server, an account on it and the IMAP extension. That is a fact about the
 * environment rather than about the work, and it decides the honest shape of
 * this interface: what is built is the SHAPE and the REFUSAL, and the refusal is
 * the part that must not be faked.
 *
 * See {@see CannotReadMailbox} for the argument in full. In one line: an empty
 * inbox that means "not connected" is a lie the reader cannot tell from "no
 * mail", so the unconfigured path throws and never returns an empty page.
 *
 * ── Nothing here writes a message down ───────────────────────────
 *
 * The owner: *"we don't want to store thousands and thousands of emails on our
 * database."* So every method on this interface RETURNS data and none of them
 * persists any. Headers may be cached — see {@see HeaderCache}, which states
 * what and for how long — and a cache is not a mirror: it is short, it is in the
 * cache store rather than a table, and losing it costs a round trip and nothing
 * else.
 *
 * ── Search is the provider's ─────────────────────────────────────
 *
 * {@see search()} passes the query through rather than filtering a local list.
 * There is no local list to filter, and building one is the sync engine that was
 * ruled out. It also means search covers the WHOLE mailbox rather than the pages
 * somebody happens to have opened, which is what anybody searching a mailbox
 * expects and is not something a cache could ever provide.
 */
interface MailboxReader
{
    /**
     * A page of headers, newest first.
     *
     * @param  string|null  $cursor  the opaque continuation from a previous page
     *
     * @throws CannotReadMailbox when the mailbox cannot be opened — never an
     *                           empty page standing in for a failure
     */
    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25): MessagePage;

    /**
     * The provider's own search, over the whole mailbox.
     *
     * @throws CannotReadMailbox
     */
    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25): MessagePage;

    /**
     * One message, opened — with the body already turned into blocks.
     *
     * The implementation is required to run the body through
     * {@see BodySanitiser} before constructing {@see MessageDetail}, and the
     * types are arranged so that it has no alternative: MessageDetail has no
     * field that could carry markup.
     *
     * @throws CannotReadMailbox
     */
    public function open(Mailbox $mailbox, string $messageId): MessageDetail;

    /**
     * Send a reply AS THE MAILBOX.
     *
     * The owner: *"info replies as info"*. Never as the individual who typed it
     * — a customer who wrote to info@ and gets an answer from
     * jenny.smith@ has been handed a person's address they did not ask for, and
     * their reply then goes somewhere only one person can see, which undoes the
     * whole point of a shared inbox.
     *
     * Who actually typed it is recorded in the audit log, which is where an
     * internal question about who said what belongs.
     *
     * @throws CannotReadMailbox  when the mailbox cannot be opened at all
     * @throws CannotReachHost    when the SMTP host fails the same guard the IMAP host does
     */
    public function reply(Mailbox $mailbox, string $messageId, string $body): void;

    /**
     * Is this reader actually able to do any of the above?
     *
     * Asked so that a screen can say "not connected" without provoking an
     * exception it would only catch and discard, and so the mailbox list can
     * show state per row without four failing round trips.
     */
    public function isConfigured(Mailbox $mailbox): bool;

    /**
     * Why not, in a sentence, or null when it is configured.
     *
     * A sentence rather than a code, because this one goes straight onto the
     * screen — R8.
     */
    public function unavailableBecause(Mailbox $mailbox): ?string;
}
