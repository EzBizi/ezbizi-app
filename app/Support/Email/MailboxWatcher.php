<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;

/**
 * Polling ONE shared inbox, once — the half of the watcher that is not a loop.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  ONE MESSAGE RAISES ONE EVENT. EVER.
 *
 *  Not "usually", and not "unless two sweeps overlap". That promise
 *  is what makes `email.received` usable for the rules people
 *  actually want — raise a job, tell the branch, chase a thread — and
 *  the moment it is broken once, every rule built on it becomes
 *  something to be suspicious of.
 *
 *  Three things hold it, and each covers a case the others do not:
 *
 *   1. THE CLAIM. A mailbox is taken by a conditional update before
 *      anything is dialled. Two sweeps running at once cannot both
 *      own it, so the mail service is not asked the same question
 *      twice and somebody's quota is not spent twice.
 *
 *   2. THE TRANSACTION. The events and the new position are written
 *      together. Announce first and crash, and the mark stays put and
 *      the whole batch is announced again on the next poll. Advance
 *      first and crash, and the batch is lost for ever. Neither can
 *      happen: it is one commit.
 *
 *   3. THE COMPARE-AND-SET INSIDE IT. The row is re-read under a lock
 *      and the mark it still carries is checked against the one this
 *      poll started from. If it has moved, another sweep has already
 *      announced these — a slow poll can outlive its own claim — and
 *      this batch is DISCARDED rather than written. Nothing is lost:
 *      whoever moved the mark announced the same messages or more.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── The health path is NOT here, deliberately ────────────────────
 *
 * Every reader this asks for comes from {@see MailboxReaders::for}, which wraps
 * it in {@see RecordingMailboxReader}. So a successful poll stamps
 * `last_reached_at` and a failed one writes the sentence on the row and raises
 * `email.mailbox_broken` — all of it for free, all of it the same code the
 * settings screen and the message list already go through.
 *
 * That is worth more than the lines it saves. Before this class, that alert
 * could only fire when a HUMAN opened a broken inbox, which the trigger's own
 * description had to admit: an inbox nobody looked at for a fortnight raised
 * nothing for a fortnight. A timer notices within minutes now, and it does so
 * without a second opinion about what "broken" means.
 *
 * ── Tenancy is the caller's job, and it is checked here ──────────
 *
 * A scheduled command has no request and therefore no tenant, and TenantScope
 * fails closed. {@see \App\Console\Commands\WatchMailboxes} enters one
 * organization at a time and hands mailboxes here one at a time; everything
 * below reads and writes through the ordinary scoped query builder, so a
 * mailbox belonging to somebody else is not merely unlikely to be polled — it
 * cannot be found, and {@see EventEmitter} would stamp the event with the
 * entered organization in any case.
 *
 * ── There is deliberately no `idOrFail()` guard at the top ───────
 *
 * There was one, and a mutation run showed it changed nothing that any test
 * could see: deleting it left the behaviour identical, because the first thing
 * this method does that touches the database is the claim, and TenantScope
 * throws there. A second statement of a rule that is already enforced is a line
 * that can only drift — it would go on looking like the protection long after
 * somebody had added a path around it. The enforcement is TenantScope, once, and
 * MailboxWatchTest asserts that a poll with no tenant entered fails rather than
 * proceeding.
 */
final class MailboxWatcher
{
    public function __construct(
        private readonly MailboxReaders $readers,
        private readonly EventEmitter $events,
    ) {}

    /**
     * Look at one mailbox. The organization must already be entered.
     *
     * Every failure is caught and turned into a verdict rather than thrown: a
     * sweep visits many mailboxes, and one mail server having a bad afternoon
     * must not stop the other ninety-nine being looked at.
     */
    public function poll(Mailbox $mailbox): PollResult
    {
        $reader = $this->readers->for($mailbox);

        /*
         * Asked BEFORE the claim and before anything is dialled, because it
         * opens no socket and costs nothing. It catches the two cases the `due`
         * query cannot see: a mailbox whose credential has been removed, and a
         * provider this INSTALLATION has nothing behind — a Google inbox on a
         * box with no OAuth client registered would otherwise be dialled every
         * five minutes for ever, and every one of those attempts would fail in a
         * way that says nothing is wrong with the mailbox.
         */
        if (($unavailable = $reader->unavailableBecause($mailbox)) !== null) {
            $this->deferOnly($mailbox);

            return PollResult::skipped($unavailable);
        }

        if (! $this->claim($mailbox)) {
            return PollResult::busy();
        }

        try {
            if ($mailbox->watch_mark === null) {
                /*
                 * THE MOST IMPORTANT FOUR LINES IN THIS FEATURE.
                 *
                 * A newly connected inbox has four thousand messages in it and
                 * not one of them arrived — they were already there. So the
                 * first sight takes the provider's position and announces
                 * NOTHING, and it does it by calling a method that has no way
                 * to return a message at all. See
                 * {@see MailboxReader::currentMark}.
                 */
                return $this->settle($mailbox, $reader->currentMark($mailbox), PollResult::marked());
            }

            if ($mailbox->watchMarkHasGoneStale($this->seconds('stale_after_seconds', 86400))) {
                /*
                 * Nobody has stood on this position for a day. Whatever arrived
                 * meanwhile cannot be told from what was already there, and the
                 * same rule applies as on first sight — see {@see Arrivals}.
                 */
                return $this->settle($mailbox, $reader->currentMark($mailbox), PollResult::remarked(
                    'unwatched for over a day — position retaken, gap not announced'
                ));
            }

            $arrivals = $reader->arrivalsSince(
                $mailbox,
                $mailbox->watch_mark,
                max(1, (int) config('ezbizi.mail_watch.per_poll', 50)),
            );
        } catch (CannotReadMailbox | CannotReachHost $e) {
            /*
             * The row has ALREADY been updated by the recording wrapper — the
             * sentence, and `email.mailbox_broken` if this means broken. What is
             * left to do here is decide when to try again, which is the one
             * question that wrapper has no opinion about.
             */
            $this->backOff($mailbox);

            return PollResult::failed($e->getMessage());
        }

        if ($arrivals->markWasStale) {
            return $this->settle($mailbox, $arrivals->mark, PollResult::remarked(
                'position no longer recognised — retaken, gap not announced'
            ));
        }

        return $this->announce($mailbox, $arrivals);
    }

    // ── the claim ────────────────────────────────────────────────

    /**
     * Take this mailbox, or discover somebody else has.
     *
     * ── Why a conditional UPDATE and not a cache lock ────────────
     *
     * Because the thing being claimed is a row, and the claim has to survive the
     * process that took it dying. Pushing `watch_next_poll_at` forward IS the
     * lease: it expires by arithmetic rather than by anybody remembering to
     * release it, so a sweep killed mid-poll costs this mailbox one cycle and
     * nothing else. The scheduler's own `withoutOverlapping` is a cache lock and
     * its header in `routes/console.php` records what a stranded one costs.
     *
     * A raw update rather than a model save, on purpose: `where ... and update`
     * in one statement is the only shape where two sweeps arriving together
     * cannot both win. Reading the row, deciding, and saving is the same race
     * with more steps.
     *
     * It still goes through the ordinary Eloquent builder, so TenantScope adds
     * the organization to the WHERE. A claim that could reach outside the
     * entered tenant would be one statement in the whole feature that did.
     *
     * ── `toBase()`, and it is not a micro-optimisation ───────────
     *
     * An Eloquent `update()` silently appends `updated_at = now()`. That column
     * means "when did somebody last change this mailbox", and a timer claiming
     * every mailbox every five minutes would rewrite it on every row in the
     * system for ever — destroying the one fact it carries, on the very path
     * {@see write} takes trouble to protect. `toBase()` applies the scopes and
     * then hands back the plain query builder, which writes exactly the column
     * named and nothing else.
     */
    private function claim(Mailbox $mailbox): bool
    {
        $now = now();

        return Mailbox::query()
            ->whereKey($mailbox->getKey())
            ->where(fn ($q) => $q->whereNull('watch_next_poll_at')->orWhere('watch_next_poll_at', '<=', $now))
            ->toBase()
            ->update(['watch_next_poll_at' => $now->copy()->addSeconds($this->interval())]) === 1;
    }

    // ── writing down what happened ───────────────────────────────

    /**
     * A new position and no announcements — first sight, or a stale mark.
     */
    private function settle(Mailbox $mailbox, string $mark, PollResult $result): PollResult
    {
        return $this->commit($mailbox, $mark, []) === null ? PollResult::busy() : $result;
    }

    /**
     * The batch, and the position immediately after it, in one commit.
     */
    private function announce(Mailbox $mailbox, Arrivals $arrivals): PollResult
    {
        $announced = $this->commit($mailbox, $arrivals->mark, $arrivals->messages);

        return $announced === null ? PollResult::busy() : PollResult::announced($announced);
    }

    /**
     * Write the events and the mark together, or write neither.
     *
     * @param  MessageSummary[]  $messages
     * @return int|null  how many were announced, or null when another sweep had
     *                   already moved the mark and this batch is theirs
     */
    private function commit(Mailbox $mailbox, string $mark, array $messages): ?int
    {
        $startedFrom = $mailbox->watch_mark;

        return DB::transaction(function () use ($mailbox, $mark, $messages, $startedFrom) {
            /*
             * Re-read under a row lock, and compared against what this poll
             * started from.
             *
             * The claim above normally makes this impossible, and "normally" is
             * the word that matters: a poll slower than the cadence outlives its
             * own lease, a second sweep claims the mailbox legitimately, and
             * both are then holding batches that begin at the same place. The
             * loser discards. Without this the two would insert the same events
             * twice and the mark would end up wherever the later commit put it,
             * which could be BEHIND — announcing the same mail a third time on
             * the next poll.
             */
            $row = Mailbox::query()->whereKey($mailbox->getKey())->lockForUpdate()->first();

            if ($row === null || $row->watch_mark !== $startedFrom) {
                return null;
            }

            foreach ($messages as $message) {
                $this->events->emit('email.received', $row, $this->payload($row, $message));
            }

            $row->standOn($mark);
            $row->deferWatchUntil(now()->addSeconds($this->interval()));

            $this->write($row);

            return count($messages);
        });
    }

    /**
     * What the event carries — and the list is short on purpose.
     *
     * ═══════════════════════════════════════════════════════════════
     *  NOT ONE CHARACTER OF THE MESSAGE.
     *
     *  No body, and no snippet either. The snippet is the one piece
     *  of message text this module handles at all — it exists so a
     *  list screen is readable, it lives in a cache measured in
     *  seconds, and {@see MessageSummary} says why that is tolerable
     *  there. It is NOT tolerable here: an outbox row is durable for
     *  three months, it is read by tenant-authored rules, and an
     *  action that emails it onward would put the first line of a
     *  customer's message somewhere nobody agreed it could go.
     *
     *  So the payload is headers: which inbox, who wrote, what about,
     *  when, whether anything is attached, and the provider's own id
     *  for the message. Exactly what the trigger's description
     *  promises, which is the point — the description is a contract
     *  and this is where it is either kept or quietly broken.
     * ═══════════════════════════════════════════════════════════════
     *
     * `message_id` is the opaque provider id — the same value `message_seen`
     * stores and nothing else. It is here because without it this module's one
     * automation action, "Mark a message as dealt with", would have nothing to
     * act on: a rule that fires on an arrival and then cannot name the message
     * it fired for is a rule that can only send notifications.
     *
     * @return array<string, mixed>
     */
    private function payload(Mailbox $mailbox, MessageSummary $message): array
    {
        return [
            // The same two keys `email.mailbox_broken` uses, spelled the same
            // way. A rule author writing conditions across both triggers should
            // not have to learn that one says `address` and the other `mailbox`.
            'address'      => $mailbox->address,
            'provider'     => $mailbox->provider,
            'display_name' => $mailbox->display_name,

            'message_id'   => $message->id,
            'subject'      => $message->subject,
            'from_name'    => $message->fromName,
            'from_address' => $message->fromAddress,

            // The mail service's own time, not ours. "When did this arrive" is
            // a question about the message; `occurred_at` on the event answers
            // the different question of when EzBizi noticed, and a rule about
            // office hours wants the first one.
            'received_at'  => $message->sentAt?->toIso8601String(),

            // A header-level fact, and the one condition somebody reaches for
            // straight away: a photograph or a signed form arriving is a
            // different event from a question.
            'has_attachments' => $message->hasAttachments,
        ];
    }

    // ── waiting ──────────────────────────────────────────────────

    /**
     * A failure that is not the mailbox being broken. Wait longer each time.
     *
     * Doubling from the ordinary cadence and capped, so a mail service that is
     * rate-limiting this installation is asked less and less rather than at the
     * same rate for ever — which is how a temporary limit becomes a permanent
     * block. The streak is cleared the moment a poll succeeds
     * ({@see Mailbox::standOn}), so one bad afternoon does not leave a healthy
     * inbox on a six-hour cadence the next morning.
     *
     * The failures that mean the connection is genuinely broken do not reach
     * here twice: the recording wrapper has already written `broken` on the row,
     * and the sweep's own query does not select a broken mailbox at all. So this
     * is the backoff for the things worth retrying, and only those.
     */
    private function backOff(Mailbox $mailbox): void
    {
        $mailbox->noteWatchFailure();

        $wait = min(
            $this->interval() * (2 ** min($mailbox->watch_failures, 12)),
            max($this->interval(), $this->seconds('max_backoff_seconds', 21600)),
        );

        $mailbox->deferWatchUntil(now()->addSeconds((int) $wait));

        $this->write($mailbox);
    }

    /**
     * Not looked at, and not counted as a failure either.
     *
     * A mailbox nobody has finished connecting is not failing — there is nothing
     * there to fail — so it goes back in the queue at the ordinary cadence
     * rather than backing off. The moment somebody finishes connecting it, the
     * next sweep within five minutes marks it.
     */
    private function deferOnly(Mailbox $mailbox): void
    {
        $mailbox->deferWatchUntil(now()->addSeconds($this->interval()));

        $this->write($mailbox);
    }

    /**
     * Save, without pretending a poll was an edit.
     *
     * The same argument {@see RecordingMailboxReader::persist} makes and for the
     * same column: `updated_at` means "when did somebody last change this
     * mailbox", and a timer moving it every five minutes would destroy the one
     * fact it carries — permanently, and on every mailbox at once.
     *
     * `save()` rather than `saveQuietly()`, also for that class's reason: the
     * model events are where BelongsToTenant refuses to write another
     * organization's row, and this runs in a command that visits every
     * organization there is. Skipping them to save a little noise would skip
     * the check on the one path where it matters most.
     */
    private function write(Mailbox $mailbox): void
    {
        $timestamps = $mailbox->timestamps;

        $mailbox->timestamps = false;

        try {
            $mailbox->save();
        } finally {
            $mailbox->timestamps = $timestamps;
        }
    }

    private function interval(): int
    {
        return $this->seconds('interval_seconds', 300);
    }

    /** A configured number of seconds, floored at one so nothing can busy-loop. */
    private function seconds(string $key, int $fallback): int
    {
        return max(1, (int) config("ezbizi.mail_watch.{$key}", $fallback));
    }
}
