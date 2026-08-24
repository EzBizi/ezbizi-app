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
    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage;

    /**
     * The provider's own search, over the whole mailbox.
     *
     * @throws CannotReadMailbox
     */
    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage;

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
     * Move this message (and its thread, where the provider groups) to Trash.
     *
     * @throws CannotReadMailbox  when the mailbox cannot be opened at all
     * @throws CannotChangeMail   when this connection cannot bin mail
     */
    public function trash(Mailbox $mailbox, string $messageId): void;

    /**
     * One attached file, as bytes. Never stored.
     *
     * @throws PictureUnavailable when the part is missing or too large
     * @throws CannotReadMailbox
     */
    public function downloadAttachment(Mailbox $mailbox, string $messageId, string $attachmentId): EmailPicture;

    /**
     * Where this mailbox is RIGHT NOW, without looking at a single message.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS IS THE METHOD THAT STOPS A NEW MAILBOX SHOUTING.
     *
     *  Connect an inbox with four thousand messages in it and the
     *  obvious watcher announces four thousand arrivals, every one of
     *  them a lie: those messages did not arrive, they were already
     *  there. The fix is not a filter or a date cutoff — both of
     *  those are guesses — it is that the FIRST look asks the
     *  provider for its position and nothing else, writes it down,
     *  and says nothing at all.
     *
     *  So this exists separately from {@see arrivalsSince} rather
     *  than being "arrivals since the beginning". It cannot return a
     *  message, because it has no field to put one in.
     * ═══════════════════════════════════════════════════════════════
     *
     * It IS a round trip — Gmail is asked for the account's history id, an IMAP
     * server is asked to open the INBOX — so it is proof the credential still
     * works, and {@see RecordingMailboxReader} records it as such.
     *
     * @return string opaque here; only the reader that issued it may read it
     *
     * @throws CannotReadMailbox
     */
    public function currentMark(Mailbox $mailbox): string;

    /**
     * What has reached this mailbox since `$mark`.
     *
     * ── `$limit` is a promise about the WORST case, not the usual one ──
     *
     * A mailbox is normally polled every few minutes and nothing or almost
     * nothing has arrived. The batch matters for the other case: a watcher that
     * has been stopped for an hour, or a mailbox that has just taken a mailshot.
     * Announcing nine hundred messages in one sweep is a queue nobody asked for
     * and a rule firing nine hundred times in one second.
     *
     * So a reader returns a BATCH, OLDEST FIRST, and the invariant every
     * implementation owes is this one:
     *
     *   THE MARK RETURNED SITS IMMEDIATELY AFTER THE LAST MESSAGE RETURNED.
     *
     * That is what makes a short batch resumable — the next poll starts exactly
     * where this one stopped, so nothing is lost and nothing is announced twice.
     *
     * `$limit` is how many the caller would like, and how precisely it can be
     * honoured depends on how finely the provider lets a position be taken.
     * {@see ImapMailboxReader} can stop after any message, because a UID is a
     * position. {@see GmailMailboxReader} can only stop where Google's history
     * paging stops, so it treats this as a page size and may return more; its
     * own note says so. Neither may return a message it cannot then mark past.
     *
     * ── A mark that is no longer usable is NOT an error ──────────
     *
     * It is a fact about mailboxes: UIDVALIDITY changes when one is recreated,
     * a Gmail history id ages out. The reader answers with an empty
     * {@see Arrivals} carrying a fresh mark and `markWasStale`, and does NOT
     * throw — see that class for why announcing the inbox instead would be the
     * same disaster as a first poll that shouted.
     *
     * @param  string  $mark  a value this same reader returned earlier
     *
     * @throws CannotReadMailbox when the mailbox cannot be opened at all —
     *                           which a stale mark is not
     */
    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals;

    /**
     * How many unread messages the provider reports for this mailbox.
     *
     * A mailbox-level figure — Gmail's profile `messagesUnread`, IMAP
     * `STATUS INBOX (UNSEEN)`, Graph's inbox `unreadItemCount` — not a page of
     * headers and not a count of rows we store. Nothing here is persisted.
     *
     * @throws CannotReadMailbox when the mailbox cannot be asked
     */
    public function unreadCount(Mailbox $mailbox): int;

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

    /**
     * Why this provider cannot work on this INSTALLATION at all — null when it
     * can. Nothing to do with any one mailbox.
     *
     * ── Why this is separate from unavailableBecause ─────────────
     *
     * That method answers "why can I not open THIS address", which needs a
     * mailbox, so it can only be asked about an inbox that already exists. The
     * screen where somebody ADDS one has no mailbox to ask about, and it was
     * guessing instead: it keyed the password form on `needs_host`, on the
     * reasoning that a provider needing a host must want a password.
     *
     * That guess was wrong in the direction that costs the most. `imap` was
     * removed from PHP core in 8.4 and this box runs 8.4.24, so IMAP cannot
     * open a socket here — and the form still asked for the password to
     * somebody's real mailbox and stored it, for a connection that could never
     * be attempted. Gmail, which is equally unbuilt, was correctly marked as
     * such, purely because it happens not to need a host.
     *
     * So the installation's own answer is published rather than inferred. A
     * provider that cannot work says so in a sentence, and no client has to
     * deduce it from a property that means something else.
     */
    public function notReadyBecause(): ?string;
}
