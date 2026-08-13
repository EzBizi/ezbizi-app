<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\Email\MailboxWatcher;
use App\Support\Email\PollResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Look at the shared inboxes, so that "an email arrived" can be a trigger.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHAT THIS MADE TRUE THAT WAS NOT.
 *
 *  `email.received` was declared and could not fire, and its
 *  description said so at length: mail was fetched from the mail
 *  service only while somebody had the screen open, so no code
 *  anywhere learned that a message existed until a human looked.
 *  Every rule a service business would actually want hangs off that
 *  event — a message from a known customer raising a job, a thread
 *  nobody has answered in two days — and none of them could be built.
 *
 *  This is the thing that looks. It does not dispatch anything and it
 *  does not run any rule: it INSERTS OUTBOX ROWS, exactly as every
 *  other write path in the application does, and
 *  `ezbizi:dispatch-automation-events` reads them a minute later.
 *  `routes/console.php` explains at length why the write path never
 *  queues a job itself, and a background poller is no exception to
 *  that — it is the case the argument was written for.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── The shape of a sweep, and why it is organization-first ───────
 *
 * A scheduled command has no request, so there is no tenant, and TenantScope
 * fails closed rather than returning everybody's rows. Finding the work
 * therefore has to happen unscoped — one deliberate, greppable query — and
 * everything after it happens INSIDE an organization, entered on purpose, one
 * at a time.
 *
 * Grouping by organization is not merely tidy. It is what makes the two
 * questions that are asked per-organization — is anybody listening, and is this
 * subscription still live — cost one query each rather than one per mailbox,
 * and it is what lets the mailboxes themselves be re-read through the ORDINARY
 * scoped builder. That last point is the tenancy guarantee: after the entry, a
 * mailbox belonging to another organization is not unlikely to be polled, it is
 * unfindable.
 *
 * ── An organization nobody is listening in is not polled at all ──
 *
 * The single largest saving available, and the tenant's own off switch. An
 * organization with no live automation on `email.received` has asked for
 * nothing, so nothing is asked of its mail service: no API quota spent, no
 * socket opened, no position kept. Switch a rule on and the mailboxes are marked
 * within one cadence; switch it off and the polling stops with it.
 *
 * That is a better control than a checkbox in settings would have been, because
 * it cannot disagree with itself. A separate "watch my inboxes" setting left ON
 * with every rule OFF would poll for ever for nothing, and the same setting left
 * OFF with a rule ON would be a rule that validates, saves, switches on and
 * waits for ever — the exact failure `isRaised: false` exists to warn about.
 *
 * ── The cost, stated ─────────────────────────────────────────────
 *
 * At a hundred watched mailboxes on a five-minute cadence: twenty polls a
 * minute. A quiet Gmail poll is one HTTPS request; a quiet IMAP poll is one
 * connection, handshake, sign-in, EXAMINE and sign-off — one every three
 * seconds across all of them, which is what a small mail server can live with
 * and a poll-every-minute design is not. A poll that FINDS mail costs one more
 * round trip per message on Gmail (the headers) and one more in total on IMAP
 * (a single fetch for the batch). `config/ezbizi.php` carries that arithmetic
 * beside the numbers it decides.
 */
class WatchMailboxes extends Command
{
    protected $signature = 'ezbizi:watch-mailboxes
                            {--limit= : how many mailboxes this sweep may poll}
                            {--seconds= : stop starting new mailboxes after this long}';

    protected $description = 'Poll the shared inboxes and raise email.received for new mail';

    /**
     * How many mailboxes this sweep has started, across every organization.
     *
     * On the command rather than threaded through three signatures, because
     * both bounds — the count and the deadline — are about the SWEEP and not
     * about an organization. Passing a per-organization count instead is how a
     * limit of twenty-five comes to mean twenty-five per tenant, which at fifty
     * tenants is not a limit.
     */
    private int $polled = 0;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MailboxWatcher $watcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('ezbizi.mail_watch.enabled', true)) {
            /*
             * The off switch, and it answers SUCCESS.
             *
             * A disabled watcher is a configuration somebody chose, not a fault,
             * and a scheduled command that returns a failure code every minute
             * on a box where it is deliberately off is an alert that trains
             * people to ignore alerts. It says so out loud instead — the one
             * thing worse than a watcher that cannot be stopped is one that has
             * been stopped and says nothing about it.
             */
            $this->info('Inbox watching is switched off on this installation (EZBIZI_MAIL_WATCH).');

            return self::SUCCESS;
        }

        $limit    = max(1, (int) ($this->option('limit') ?: config('ezbizi.mail_watch.per_sweep', 25)));
        $deadline = microtime(true)
            + max(1, (int) ($this->option('seconds') ?: config('ezbizi.mail_watch.sweep_seconds', 45)));

        $this->polled = 0;

        $totals = [];

        foreach ($this->organizationsWithDueMailboxes($limit) as $organizationId => $mailboxIds) {
            if ($this->polled >= $limit || $this->outOfTime($deadline)) {
                break;
            }

            $organization = Organization::withoutGlobalScopes()->find($organizationId);

            if ($organization === null) {
                continue;
            }

            /*
             * A lapsed subscription is not watched (§13).
             *
             * "An automation that keeps firing after expiry means the product
             * works fine without paying" is the plan's own wording, and a
             * background poller raising events for an organization that has
             * stopped paying is precisely that. It is also the honest reading of
             * the read-only state: the engine would refuse every action these
             * events caused, so the polling would spend a mail service's quota
             * to fill a table with events nothing may act on.
             *
             * Their mail is untouched and still readable on screen, which is
             * what §13 says must keep working.
             */
            if ($organization->isReadOnly()) {
                continue;
            }

            $this->sweepOrganization($organization, $mailboxIds, $limit, $deadline, $totals);
        }

        $this->report($this->polled, $totals);

        return self::SUCCESS;
    }

    /**
     * Has this sweep been going long enough that it should stop starting?
     *
     * ── It never stops the FIRST mailbox, and that is the point ──
     *
     * The budget exists so that one slow mail server cannot push a run past the
     * next minute's tick and into the overlap lock. It must not become a reason
     * to do nothing: on a loaded box the clock could already be past the
     * deadline by the time the work is found, and a sweep that then polled
     * nothing — every minute, silently — would look exactly like a watcher that
     * works and never fires.
     *
     * So the deadline only ever ends a sweep that has already achieved
     * something. Progress is guaranteed; the bound is on how much more.
     */
    private function outOfTime(float $deadline): bool
    {
        return $this->polled > 0 && microtime(true) >= $deadline;
    }

    /**
     * Every mailbox that is due, grouped by the organization that owns it.
     *
     * ── The one unscoped query, and what keeps it honest ─────────
     *
     * `withoutGlobalScopes()` is the greppable way of saying "this genuinely
     * spans tenants", which TenantScope's own note names as one of the two
     * legitimate answers. What comes back is a list of IDS and nothing else —
     * no addresses, no credentials, no models that anything could act on — and
     * every one of them is re-read inside its own organization before it is
     * touched. So the cross-tenant reach of this method ends at this line.
     *
     * ── What the WHERE clause is doing ───────────────────────────
     *
     * Only CONNECTED mailboxes. A broken one is deliberately not retried by the
     * timer: somebody has to reconnect it, the settings screen already says so,
     * and polling it would be a failing connection to somebody's mail server
     * every five minutes for as long as nobody notices. Reconnecting sets the
     * status back and the next sweep picks it up. An unconfigured one has
     * nothing to poll at all.
     *
     * Ordered by how overdue each is, nulls first — MySQL sorts NULL before any
     * value ascending, and null here means "never looked at", which is as
     * overdue as it gets. A newly connected inbox is therefore marked before an
     * old one is re-polled, which is also the order a person would expect.
     *
     * @return Collection<string, string[]> organization id => mailbox ids
     */
    private function organizationsWithDueMailboxes(int $limit): Collection
    {
        return Mailbox::withoutGlobalScopes()
            ->where('status', Mailbox::STATUS_CONNECTED)
            ->where(fn ($q) => $q->whereNull('watch_next_poll_at')->orWhere('watch_next_poll_at', '<=', now()))

            /*
             * ── EVERY exclusion is in SQL, and that is a fix, not tidying ──
             *
             * The two below were applied in PHP after this page had been taken,
             * with `limit($limit * 4)` as slack against a page that turned out
             * to be entirely skippable. Slack is not a fix, and the arithmetic
             * says so: a skipped organization never has `watch_next_poll_at`
             * written, so its rows stay NULL — and NULL sorts FIRST. They
             * refill the page every sweep, for ever.
             *
             * Measured at the boundary: 99 such mailboxes and a listening
             * organization is still polled; 100 and it is never polled again.
             * The command then reports "No shared inbox was due to be looked
             * at", which is indistinguishable from there being no work.
             *
             * And that is the state on deployment day, not an exotic one. Every
             * existing mailbox row starts with a NULL mark, and every
             * organization that has not yet written an `email.received` rule
             * keeps its rows NULL indefinitely. The first hundred of those
             * would have switched the watcher off for the whole installation,
             * silently.
             *
             * Filtering here means the page can only contain rows this sweep
             * would actually poll, so nothing skippable can occupy it.
             */
            ->whereExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('organizations')
                ->whereColumn('organizations.id', 'mailboxes.organization_id')
                ->where('organizations.is_active', true)
                // The lapsed-trial half of Organization::isReadOnly(). §13: an
                // automation that keeps firing after expiry means the product
                // works without paying.
                ->where(fn ($o) => $o
                    ->whereNull('organizations.trial_ends_at')
                    ->orWhere('organizations.trial_ends_at', '>', now())))

            // Nobody listening, nothing to announce to. The tenant's own switch
            // is the rule itself — see anybodyIsListening() for why that is
            // better than a second setting that could disagree with it.
            ->whereExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('automations')
                ->whereColumn('automations.organization_id', 'mailboxes.organization_id')
                ->where('automations.trigger_key', 'email.received')
                ->where('automations.is_active', true)
                ->whereNull('automations.disabled_at')
                ->whereNotNull('automations.active_version_id'))

            ->orderBy('watch_next_poll_at')
            /*
             * Still more than the sweep will poll, for the one case SQL cannot
             * pre-empt: a mailbox whose organization row vanishes between this
             * query and the read below. Slack for a rarity, rather than slack
             * standing in for a filter.
             */
            ->limit($limit * 4)
            ->get(['id', 'organization_id'])
            ->groupBy('organization_id')
            ->map(fn (Collection $rows) => $rows->pluck('id')->all());
    }

    /**
     * One organization, entered on purpose, for as long as it takes.
     *
     * @param  string[]  $mailboxIds
     * @param  array<string, int>  $totals
     */
    private function sweepOrganization(
        Organization $organization,
        array $mailboxIds,
        int $limit,
        float $deadline,
        array &$totals,
    ): void {
        $this->tenant->run($organization, function () use ($mailboxIds, $limit, $deadline, &$totals) {
            if (! $this->anybodyIsListening()) {
                return;
            }

            /*
             * Re-read through the SCOPED builder.
             *
             * The ids came from a query that deliberately spanned tenants; this
             * one does not, so TenantScope adds `organization_id = <entered>`
             * and an id that does not belong here simply does not come back.
             * That is the difference between believing the grouping above was
             * right and being unable to act on it if it was wrong.
             *
             * No test can kill this line on its own, and a mutation run
             * confirmed it: swapping it for `withoutGlobalScopes()` changes
             * nothing observable while the grouping is correct, which it is.
             * That is what makes it worth keeping rather than what makes it
             * dead — this is not an extra guard bolted on, it is simply the
             * ordinary way to write the query, and the mutation is the
             * deliberate act of removing a lock.
             */
            $mailboxes = Mailbox::query()->whereKey($mailboxIds)->get();

            foreach ($mailboxes as $mailbox) {
                if ($this->polled >= $limit || $this->outOfTime($deadline)) {
                    break;
                }

                $this->polled++;

                $result = $this->pollOne($mailbox);

                // Two different counts, kept apart on purpose: how many
                // mailboxes ended each way, and how many messages were
                // announced. One line reporting both as a single number is how
                // "twelve" comes to mean twelve inboxes on Monday and twelve
                // emails on Tuesday.
                $totals[$result->verdict] = ($totals[$result->verdict] ?? 0) + 1;
                $totals['messages']       = ($totals['messages'] ?? 0) + $result->announced;

                /*
                 * The ordinary outcome is silent, and everything else is not.
                 *
                 * This runs sixty times an hour for ever. A line per mailbox per
                 * sweep is twenty-eight thousand lines a day saying "nothing had
                 * arrived", which is not a log anybody reads — and a log nobody
                 * reads is where the one line that mattered goes to hide.
                 *
                 * `-v` prints every one, because "is it even looking at this
                 * mailbox?" is the first question anybody debugging this asks
                 * and it must be answerable without editing anything.
                 */
                if ($result->verdict !== PollResult::QUIET || $this->output->isVerbose()) {
                    $this->line(sprintf('  %-34s %-10s %s', $mailbox->address, $result->verdict, $result->note));
                }
            }
        });
    }

    /**
     * One mailbox, with anything unexpected contained.
     *
     * {@see MailboxWatcher::poll} already turns every failure the mail service
     * can produce into a verdict. This catches what is left — a bug, a database
     * hiccup, a provider this build does not recognise — for one reason: a sweep
     * that dies on mailbox three leaves mailboxes four to twenty-five unpolled
     * every single minute, and the symptom is "the watcher does not work" rather
     * than "one mailbox is misconfigured".
     */
    private function pollOne(Mailbox $mailbox): PollResult
    {
        try {
            return $this->watcher->poll($mailbox);
        } catch (Throwable $e) {
            report($e);

            return PollResult::failed($e->getMessage());
        }
    }

    /**
     * Is there a live rule in this organization waiting for an arrival?
     *
     * The same four conditions the engine itself uses to decide a rule may run —
     * switched on, not auto-disabled after repeated failure, and carrying a
     * published version. A rule that the engine would skip is not a reason to
     * open somebody's mailbox.
     *
     * Scope is deliberately NOT considered. A franchise-scoped rule only covers
     * its own franchise's inboxes, so a stricter query could skip a mailbox no
     * rule covers — and it would cost a company-chain walk per mailbox to answer
     * a question the engine is going to ask again anyway. The saving that
     * matters is the organization that has built no rule at all, and that is the
     * one this catches.
     */
    private function anybodyIsListening(): bool
    {
        return Automation::query()
            ->where('trigger_key', 'email.received')
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->whereNotNull('active_version_id')
            ->exists();
    }

    /** @param array<string, int> $totals */
    private function report(int $polled, array $totals): void
    {
        if ($polled === 0) {
            $this->info('No shared inbox was due to be looked at.');

            return;
        }

        $this->info(sprintf(
            'Looked at %d shared inbox(es); raised %d arrival(s).',
            $polled,
            $totals['messages'] ?? 0,
        ));

        foreach ([PollResult::MARKED, PollResult::REMARKED, PollResult::FAILED] as $verdict) {
            if (($count = $totals[$verdict] ?? 0) > 0) {
                // The three worth saying twice. A mailbox marked for the first
                // time or re-marked has had a gap that was deliberately NOT
                // announced, and a gap nobody is told about is the one thing
                // here that could look like the watcher working perfectly.
                $this->line(sprintf('  %d %s.', $count, $verdict));
            }
        }
    }
}
