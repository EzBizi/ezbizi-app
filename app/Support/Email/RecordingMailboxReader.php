<?php

namespace App\Support\Email;

use App\Exceptions\SubscriptionLapsedException;
use App\Models\Mailbox;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;

/**
 * The one place that records whether a shared inbox is actually working.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  IT WRAPS EVERY READER, SO NO READER HAS TO REMEMBER.
 *
 *  {@see MailboxReaders::for} is the only way anything in this
 *  application obtains a MailboxReader, and it now returns one of
 *  these with the real reader inside. That is deliberate and it is the
 *  whole reason this class exists rather than two copies of the same
 *  four lines inside {@see GmailMailboxReader} and
 *  {@see ImapMailboxReader}.
 *
 *  Put the bookkeeping in the readers and it is correct exactly until
 *  somebody adds the third one. Microsoft 365 is the obvious next
 *  provider; it would be written by a person reading the Graph API
 *  docs, not this file, and the branch they would forget is the one in
 *  the error path — which is precisely the branch that decides whether
 *  an administrator ever learns their inbox stopped working.
 *
 *  Wrapped instead, a new reader gets all of this by existing.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── WHAT MOVES THE TIMESTAMP, AND WHAT MUST NOT ─────────────────
 *
 * `last_reached_at` answers one question — *when did this inbox last actually
 * work?* — and there are two easy ways to destroy that answer:
 *
 *   ON ATTEMPT. Stamping before the call, or in a `finally`, records an inbox as
 *   reached at the exact moment it was not. That is worse than having no column:
 *   a screen saying "last worked: two minutes ago" beside "broken" is a
 *   contradiction the reader will resolve by disbelieving the part that is true.
 *
 *   ON THE WAY IN FROM A REQUEST. The settings screen asks every mailbox whether
 *   it can be opened in order to draw the list. If that question moved the
 *   column, opening the tab would refresh every row and the field would answer
 *   "when did somebody last look at this screen". So {@see isConfigured} and
 *   {@see unavailableBecause} pass straight through and record NOTHING — they
 *   make no call to the provider and therefore learn nothing about it.
 *
 * What does move it: `list`, `search`, `open`, `reply`, `currentMark`,
 * `arrivalsSince` and `unreadCount` RETURNING. Each is a completed round trip
 * to the mail service in which the stored credential was accepted, which is
 * exactly what the column claims.
 *
 * `reply` is included on purpose. It is not a read, but it is proof of the same
 * fact — the mail service accepted our key and did what we asked — and an inbox
 * somebody has just answered from is not one to draw as "last worked in March".
 *
 * The last two are the background watcher's, and they are the reason the column
 * finally means something on an inbox nobody has opened: `last_reached_at` used
 * to move only when a person looked, so a mailbox that had worked perfectly for
 * a month unattended read as never having worked at all.
 *
 * ── WHAT WRITES THE SENTENCE ────────────────────────────────────
 *
 * A failure carries a {@see MailboxFault}, and the fault decides both whether
 * the row should read broken and what it should say. Not every failure does:
 * a rate limit is not a broken mailbox, an unconfigured one has nothing to break,
 * and a fault at EzBizi's end must never be written onto a tenant's row. See
 * {@see MailboxFault::marksBroken}, which argues each of those.
 */
final class RecordingMailboxReader implements MailboxReader
{
    public function __construct(
        public readonly MailboxReader $inner,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
        private readonly HeaderCache $cache,
    ) {}

    // ── the four calls that reach the provider ───────────────────

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->recording($mailbox, fn () => $this->inner->list($mailbox, $cursor, $limit, $label));
    }

    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->recording($mailbox, fn () => $this->inner->search($mailbox, $query, $cursor, $limit, $label));
    }

    public function open(Mailbox $mailbox, string $messageId): MessageDetail
    {
        return $this->recording($mailbox, fn () => $this->inner->open($mailbox, $messageId));
    }

    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $this->recording($mailbox, fn () => $this->inner->reply($mailbox, $messageId, $body));
    }

    public function canMarkReadAtSource(Mailbox $mailbox): bool
    {
        return $this->inner->canMarkReadAtSource($mailbox);
    }

    public function markReadAtSource(Mailbox $mailbox, string $messageId): void
    {
        $this->recording($mailbox, fn () => $this->inner->markReadAtSource($mailbox, $messageId));
    }

    public function availableFolders(Mailbox $mailbox): array
    {
        return $this->recording($mailbox, fn () => $this->inner->availableFolders($mailbox));
    }

    public function send(Mailbox $mailbox, array $to, string $subject, string $body, array $attachments = []): void
    {
        $this->recording($mailbox, fn () => $this->inner->send($mailbox, $to, $subject, $body, $attachments));
    }

    public function trash(Mailbox $mailbox, string $messageId): void
    {
        $this->recording($mailbox, fn () => $this->inner->trash($mailbox, $messageId));
    }

    public function downloadAttachment(Mailbox $mailbox, string $messageId, string $attachmentId): EmailPicture
    {
        return $this->recording($mailbox, fn () => $this->inner->downloadAttachment($mailbox, $messageId, $attachmentId));
    }

    /**
     * Both watch calls, recorded exactly like the four above.
     *
     * ── The background poller gets the health path for free ──────
     *
     * This is the reason the watcher asks {@see MailboxReaders::for} for its
     * reader instead of building one. Every poll is a completed round trip to
     * the mail service in which the stored credential was accepted, which is
     * what `last_reached_at` claims — and every FAILED poll is the earliest
     * possible warning that an inbox has stopped working, which is exactly what
     * `email.mailbox_broken` is for.
     *
     * A second health path inside the poller would have been the obvious thing
     * to write and would have drifted from this one within a release: two
     * definitions of "broken", two decisions about when to announce it, and the
     * settings screen agreeing with neither.
     *
     * It also quietly changes what that alert is worth. Until now the module
     * could only notice a dead inbox when somebody opened it — which the
     * trigger's own description had to admit — so an inbox nobody looked at for
     * a fortnight raised nothing for a fortnight. Now the timer notices, and the
     * "once per break, not once per attempt" rule in {@see faulted} is what
     * stops that becoming a message every five minutes.
     */
    public function currentMark(Mailbox $mailbox): string
    {
        return $this->recording($mailbox, fn () => $this->inner->currentMark($mailbox));
    }

    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals
    {
        return $this->recording($mailbox, fn () => $this->inner->arrivalsSince($mailbox, $mark, $limit));
    }

    /**
     * Cached for a minute — see {@see HeaderCache::unread} for why.
     *
     * ── Cached HERE because this is the one place every call passes ──
     *
     * `MailboxReaders::for()` wraps every reader in this class, without
     * exception, so a cache put here covers all four providers and cannot be
     * skipped by a caller that reaches for a reader some other way. Caching it
     * inside each reader instead would be the same code four times and one
     * place for the fifth provider to forget it.
     *
     * ── The cache sits INSIDE the recording, deliberately ────────
     *
     * A cached answer means no provider call, so there is no fault to record
     * and no `email.mailbox_broken` to raise — which is right: nothing was
     * tried, so nothing failed. Recording a success for a call that never
     * happened would make a broken mailbox look reachable for up to a minute
     * after it broke.
     */
    public function unreadCount(Mailbox $mailbox): int
    {
        return $this->cache->unread(
            $mailbox,
            fn () => $this->recording($mailbox, fn () => $this->inner->unreadCount($mailbox)),
        );
    }

    // ── the two that ask nothing of anybody ──────────────────────

    /**
     * Passed through, and deliberately not recorded.
     *
     * This asks whether there is a credential and whether this installation has
     * anything behind the provider. It opens no socket, so a `true` from here is
     * not evidence that the mailbox works — and the settings screen calls it once
     * per mailbox per page load, which is the one call pattern that would turn
     * `last_reached_at` into "when did you last open this tab".
     */
    public function isConfigured(Mailbox $mailbox): bool
    {
        return $this->inner->isConfigured($mailbox);
    }

    /** Passed through, for the reason above: it asks the provider nothing. */
    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        return $this->inner->unavailableBecause($mailbox);
    }

    /**
     * Passed through, and it takes no mailbox at all — this is a fact about the
     * INSTALLATION, so there is nothing here to record even in principle.
     */
    public function notReadyBecause(): ?string
    {
        return $this->inner->notReadyBecause();
    }

    // ── the recording itself ─────────────────────────────────────

    /**
     * Run the call, and write down what happened to the mailbox.
     *
     * The exception is always RE-THROWN. Recording a failure is bookkeeping
     * beside the failure, never instead of it: {@see CannotReadMailbox} exists so
     * that "we cannot look" reaches the screen as itself rather than as an empty
     * inbox, and a wrapper that swallowed it to write a status row would undo the
     * decision the whole module rests on.
     *
     * {@see CannotReachHost} is caught as well, and it is not a read failure at
     * all — it is {@see RemoteHostGuard} refusing to dial a mail server somebody
     * typed. Left unrecorded it would be the one fault invisible on the settings
     * screen, and it is among the most actionable: the address is misconfigured
     * and will never work until somebody edits it.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function recording(Mailbox $mailbox, callable $call)
    {
        try {
            $result = $call();
        } catch (CannotReadMailbox $e) {
            $this->faulted($mailbox, $e->fault);

            throw $e;
        } catch (CannotReachHost $e) {
            $this->faulted($mailbox, MailboxFault::HostNotAllowed);

            throw $e;
        }

        $this->reached($mailbox);

        return $result;
    }

    /** It answered. Stamp the time and clear any complaint left over. */
    private function reached(Mailbox $mailbox): void
    {
        $mailbox->markReached();

        $this->persist($mailbox);
    }

    /**
     * It did not answer, and the cause decides whether that is news.
     *
     * ── The event fires on the TRANSITION, not on every attempt ──
     *
     * A mailbox that has been broken since Tuesday is refused every time anybody
     * opens it. Emitting there would send a rule — *"tell the branch manager when
     * an inbox stops working"* — one message per page load for as long as nobody
     * fixes it, which is how a useful rule gets switched off in an afternoon.
     *
     * So it is raised only when the row was not already broken. A cause that
     * CHANGES while the mailbox stays broken is deliberately quiet too: the
     * sentence on the row is updated so the screen is right, and the manager who
     * has already been told is not told twice about the same dead inbox.
     */
    private function faulted(Mailbox $mailbox, MailboxFault $fault): void
    {
        // The count is the mailbox's CONSECUTIVE failed polls, and only
        // `ProviderRefused` reads it -- see `marksBroken()`. Passing it for
        // every fault rather than branching here keeps the decision in the one
        // place a new arm's author is already reading.
        if (! $fault->marksBroken((int) $mailbox->watch_failures)) {
            return;
        }

        $wasAlreadyBroken = $mailbox->status === Mailbox::STATUS_BROKEN;

        $mailbox->markBroken($fault->sentence($mailbox->address));

        if (! $this->persist($mailbox) || $wasAlreadyBroken) {
            return;
        }

        $this->announce($mailbox, $fault);
    }

    /**
     * Write it down, without pretending a read was an edit.
     *
     * ── `updated_at` is deliberately held still ──────────────────
     *
     * That column means "when did somebody last change this mailbox" — it is what
     * an administrator reads to answer "who touched this?" and what the audit
     * trail is compared against. Nobody changed anything here; a mail server
     * answered a question. Letting a page view move it would make the column say
     * the inbox was edited every minute of every working day, and the one fact it
     * exists to carry would be gone.
     *
     * ── `save()` and not `saveQuietly()` ─────────────────────────
     *
     * The model events on a tenant-owned write are not decoration:
     * App\Models\Concerns\BelongsToTenant hangs its "refuse to update another
     * organization's row" check on `updating`. Skipping events to save a little
     * noise would skip that, on a path that runs on every mail page load.
     *
     * @return bool whether the row was actually written
     */
    private function persist(Mailbox $mailbox): bool
    {
        $timestamps = $mailbox->timestamps;

        $mailbox->timestamps = false;

        try {
            return $mailbox->save();
        } catch (SubscriptionLapsedException) {
            /*
             * An organization whose subscription has lapsed is read-only, and
             * this write is caught by that floor along with every other.
             *
             * Swallowed HERE and nowhere else, because the alternative is worse
             * than a missing timestamp: the read has already succeeded — the mail
             * is fetched and in memory — and letting this turn into a 402 would
             * mean a lapsed organization could no longer READ its mail, which is
             * the one thing §13's read-only state is supposed to leave working.
             *
             * Narrow on purpose. Any other failure to write is a real fault and
             * is left to surface.
             */
            return false;
        } finally {
            $mailbox->timestamps = $timestamps;
        }
    }

    /**
     * Tell the automations outbox that an inbox has just broken.
     *
     * Guarded on tenant context rather than assuming a request: this class is
     * reachable from a console command or a queued job, and `emit` needs to know
     * which organization the event belongs to. A missing tenant means the row is
     * already written and only the event is skipped — which is the right way
     * round, since the status screen is the thing somebody is about to look at.
     */
    private function announce(Mailbox $mailbox, MailboxFault $fault): void
    {
        if (! $this->tenant->has()) {
            return;
        }

        $this->events->emit('email.mailbox_broken', $mailbox, [
            'address'  => $mailbox->address,
            'provider' => $mailbox->provider,
            // The CAUSE, not the provider's own words. A rule that branches on
            // "was it the sign-in or the host" needs something stable to compare
            // against, and `invalid_grant` is neither stable nor ours.
            'cause'    => $fault->value,
            // The same sentence the screen shows, so a rule that emails somebody
            // does not have to invent its own wording for the same fact.
            'reason'   => $mailbox->status_reason,
        ]);
    }
}
