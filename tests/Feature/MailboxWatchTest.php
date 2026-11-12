<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\Email\Arrivals;
use App\Support\Email\Block;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\EmailPicture;
use App\Support\Email\MailboxReader;
use App\Support\Email\MailboxReaders;
use App\Support\Email\Span;
use App\Support\Email\MailboxWatcher;
use App\Support\Email\MessageDetail;
use App\Support\Email\MessagePage;
use App\Support\Email\MessageSummary;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Throwable;

/**
 * `email.received` — the trigger this module exists for, now that it fires.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE TWO ASSERTIONS THAT MATTER MORE THAN THE REST PUT TOGETHER.
 *
 *  1. A MAILBOX SEEN FOR THE FIRST TIME ANNOUNCES NOTHING. The fake
 *     below starts with four thousand messages in it, because the
 *     obvious implementation announces four thousand arrivals and
 *     every one of them is false — those messages did not arrive,
 *     they were already there. A tenant's first experience of this
 *     feature would be four thousand notifications about mail they
 *     had already read.
 *
 *  2. ONE MESSAGE RAISES ONE EVENT. Across repeated sweeps, across a
 *     poll that outlives its own claim, and across a mailbox whose
 *     position is no longer valid. Every one of those is tested
 *     below by counting rows rather than by reading the code.
 *
 *  Both are counted with `assertSame(0, ...)` and `assertSame(1, ...)`
 *  on the OUTBOX TABLE, which is the only place the answer really
 *  lives.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Why the reader is a fake, and what that costs ────────────────
 *
 * There is no Google account on this installation and the IMAP guard refuses to
 * dial a loopback address — correctly, and it is not going to be weakened for a
 * test. So {@see WatchedInbox} stands in for a mail service, and it is written
 * to behave like the harder of the two: messages have positions, a mark is a
 * position, and asking for arrivals from a position returns exactly what sits at
 * or after it.
 *
 * What that leaves uncovered is each provider's own arithmetic, and that is
 * covered where the provider is: {@see \Tests\Feature\GmailMailboxWatchTest}
 * drives Gmail's history endpoint over a faked HTTP client including the
 * expired-history case, and {@see ImapMailboxReaderTest} covers the UID frontier
 * and the `n:*` trap. What is tested HERE is everything above the provider —
 * the first poll, the mark, the tenancy, the cadence, the off switches — which
 * is where all six of the requirements this feature was judged against live.
 */
class MailboxWatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WatchedInbox::reset();

        $this->app->bind(MailboxReaders::class, fn () => new WatchedInboxes);

        // Explicit rather than inherited from config/ezbizi.php, so that a
        // change to the shipped cadence cannot silently rewrite what these
        // tests believe they are asserting.
        config([
            'ezbizi.mail_watch.enabled'             => true,
            'ezbizi.mail_watch.interval_seconds'    => 300,
            'ezbizi.mail_watch.per_poll'            => 50,
            'ezbizi.mail_watch.per_sweep'           => 25,
            'ezbizi.mail_watch.sweep_seconds'       => 45,
            'ezbizi.mail_watch.stale_after_seconds' => 86400,
            'ezbizi.mail_watch.max_backoff_seconds' => 21600,
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────

    /**
     * A tenant with an inbox connected and a rule listening for arrivals.
     *
     * The rule is not decoration. An organization with no live automation on
     * this trigger is deliberately never polled — see the command — so a fixture
     * without one would make every test below pass by doing nothing at all.
     *
     * @return array{org: Organization, mailbox: Mailbox}
     */
    private function watchedTenant(string $name = 'A1-Group', string $address = 'info@a-1chimney.com'): array
    {
        $t = $this->provisionTenant($name);

        return $this->inTenant($t['org'], function () use ($t, $address) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => $address,
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);

            $mailbox->connect('the-refresh-token');
            $mailbox->save();

            $this->listenFor($t['org'], $t['admin']->id);

            return ['org' => $t['org'], 'mailbox' => $mailbox, 'admin' => $t['admin']];
        });
    }

    /** A live rule on `email.received`, which is what makes the org worth polling. */
    private function listenFor(Organization $org, string $createdBy, bool $active = true): Automation
    {
        $automation = Automation::create([
            'organization_id' => $org->id,
            'name'            => 'Tell the branch',
            'trigger_key'     => 'email.received',
            'is_active'       => $active,
            'created_by'      => $createdBy,
        ]);

        $version = AutomationVersion::create([
            'organization_id' => $org->id,
            'automation_id'   => $automation->id,
            'version'         => 1,
            'conditions'      => [],
            'actions'         => [],
            'created_by'      => $createdBy,
        ]);

        $automation->forceFill(['active_version_id' => $version->id])->save();

        return $automation;
    }

    /**
     * A rule whose conditions compare `body_matched` against `$words`.
     *
     * Built through the real models rather than by stubbing the enumerator, so
     * the test exercises the same tree-walk production uses. A rule shape the
     * walker cannot read would pass a stubbed test and fail here.
     */
    private function ruleOnBody(Organization $org, string $createdBy, array $words): void
    {
        $automation = Automation::create([
            'organization_id' => $org->id,
            'name'            => 'Missed calls',
            'trigger_key'     => 'email.received',
            'is_active'       => true,
            'created_by'      => $createdBy,
        ]);

        $version = AutomationVersion::create([
            'organization_id' => $org->id,
            'automation_id'   => $automation->id,
            'version'         => 1,
            'conditions'      => ['all' => array_map(
                fn (string $w) => ['field' => 'body_matched', 'op' => 'contains', 'value' => $w],
                $words,
            )],
            'actions'         => [],
            'created_by'      => $createdBy,
        ]);

        $automation->forceFill(['active_version_id' => $version->id])->save();
    }

    /** A rule whose notify step writes `$message` -- the way a template asks for a field. */
    private function ruleWriting(Organization $org, string $createdBy, string $message, bool $active = true): void
    {
        $automation = Automation::create([
            'organization_id' => $org->id,
            'name'            => 'New mail',
            'trigger_key'     => 'email.received',
            'is_active'       => $active,
            'created_by'      => $createdBy,
        ]);

        $version = AutomationVersion::create([
            'organization_id' => $org->id,
            'automation_id'   => $automation->id,
            'version'         => 1,
            'conditions'      => ['all' => []],
            'actions'         => [['type' => 'notify', 'message' => $message, 'details' => '{{description}}']],
            'created_by'      => $createdBy,
        ]);

        $automation->forceFill(['active_version_id' => $version->id])->save();
    }

    private function latestArrival(Organization $org): AutomationEvent
    {
        return $this->inTenant($org,
            fn () => AutomationEvent::where('event_key', 'email.received')->latest('occurred_at')->firstOrFail());
    }

    private function sweep(): void
    {
        $this->artisan('ezbizi:watch-mailboxes')->assertSuccessful();
    }

    private function arrivals(Organization $org): int
    {
        return $this->inTenant($org, fn () => AutomationEvent::where('event_key', 'email.received')->count());
    }

    private function reload(Organization $org, Mailbox $mailbox): Mailbox
    {
        return $this->inTenant($org, fn () => Mailbox::findOrFail($mailbox->getKey()));
    }

    // ═════════════════════════════════════════════════════════════
    //  REQUIREMENT 1 — the first poll announces nothing
    // ═════════════════════════════════════════════════════════════

    /**
     * Four thousand messages already in the inbox. Not one event.
     *
     * The number is deliberately absurd so that a failure is unmistakable in the
     * output: an implementation that announces the backlog does not fail this by
     * one, it fails it by four thousand.
     */
    public function test_the_first_sight_of_a_mailbox_announces_nothing_at_all(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(4000);

        $this->sweep();

        $this->assertSame(0, $this->arrivals($t['org']),
            'A mailbox seen for the first time announced mail that was already sitting in it.');

        $this->assertSame('4001', $this->reload($t['org'], $t['mailbox'])->watch_mark,
            'The first poll did not write down where the mailbox had got to, so the next one '
            .'will announce the backlog instead.');
    }

    /**
     * And the message that arrives AFTER that is announced — once.
     *
     * The other half of the same requirement, and the reason the first test is
     * not satisfiable by a watcher that simply never announces anything.
     */
    public function test_a_message_arriving_after_the_first_poll_is_announced_exactly_once(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(4000);

        $this->sweep();

        WatchedInbox::arrive('Bob Smith', 'bob@example.com', 'Quote for two flues');

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertSame(1, $this->arrivals($t['org']));

        // Three more sweeps, each a cadence apart, and still one. This is the
        // assertion that catches a mark that is read but never advanced.
        foreach (range(1, 3) as $round) {
            $this->travelTo(now()->addMinutes(6));
            $this->sweep();

            $this->assertSame(1, $this->arrivals($t['org']),
                "The same message was announced again on sweep {$round}.");
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  REQUIREMENT 2 — one message, one event, ever
    // ═════════════════════════════════════════════════════════════

    /**
     * A poll that outlives its claim does not double-announce.
     *
     * The claim normally makes this impossible, and "normally" is the word being
     * tested. Two sweeps hold the same mailbox at the same starting mark — which
     * is what happens when one of them is slower than the cadence — and the
     * second must discard its batch rather than write it, because the first has
     * already announced the same messages.
     *
     * Modelled by loading the row TWICE and letting the lease lapse between the
     * two polls, which is exactly the state the two processes would be in.
     */
    public function test_a_second_sweep_holding_a_stale_position_discards_its_batch(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(10);
        $this->sweep();

        WatchedInbox::arrive('Bob', 'bob@example.com', 'Booking');

        $this->travelTo(now()->addMinutes(6));

        [$first, $second] = $this->inTenant($t['org'], fn () => [
            Mailbox::findOrFail($t['mailbox']->getKey()),
            Mailbox::findOrFail($t['mailbox']->getKey()),
        ]);

        $watcher = app(MailboxWatcher::class);

        $this->inTenant($t['org'], function () use ($watcher, $first, $second, $t) {
            $watcher->poll($first);

            // The slow sweep's lease has lapsed while it was working, so its
            // claim succeeds — and only the compare-and-set can save it.
            Mailbox::query()->whereKey($t['mailbox']->getKey())
                ->update(['watch_next_poll_at' => now()->subMinute()]);

            $watcher->poll($second);
        });

        $this->assertSame(1, $this->arrivals($t['org']),
            'Two sweeps holding the same starting position both announced the same message.');
    }

    /**
     * A position the mail service no longer recognises starts again in SILENCE.
     *
     * The second most likely disguise of requirement 1. An IMAP mailbox deleted
     * and recreated changes UIDVALIDITY; a Gmail history id ages out. The
     * tempting recovery is to start from the beginning, and the beginning is
     * four thousand messages.
     */
    public function test_a_position_the_provider_rejects_is_retaken_without_announcing(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(2000);
        $this->sweep();

        WatchedInbox::$rejectTheMark = true;

        $this->travelTo(now()->addMinutes(6));

        /*
         * The verdict is asserted, not only the counts, and that is the whole
         * value of this test.
         *
         * Announcing nothing is what a rejected position and an ordinary quiet
         * poll have in common, so counting events alone cannot tell them apart —
         * a watcher that treated the rejection as "no mail" would pass every
         * numeric assertion here while silently skipping a gap. The operator has
         * to be told, because a gap nobody is told about is the one failure in
         * this feature that looks exactly like it working perfectly.
         */
        $this->artisan('ezbizi:watch-mailboxes')
            ->expectsOutputToContain('re-marked')
            ->assertSuccessful();

        $this->assertSame(0, $this->arrivals($t['org']),
            'A rejected position was recovered from by announcing the whole inbox.');

        $this->assertSame('2001', $this->reload($t['org'], $t['mailbox'])->watch_mark);
    }

    /**
     * A position nobody has stood on for a day is abandoned, not caught up.
     *
     * The watcher was off, or the box was down. Everything that arrived
     * meanwhile is indistinguishable from what was already there — and
     * announcing a day of mail at once, hours after anybody could act on it,
     * would fire every rule on everything simultaneously.
     */
    public function test_a_position_nobody_has_confirmed_for_a_day_is_retaken_without_announcing(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(10);
        $this->sweep();

        // Two days of arrivals nobody watched.
        foreach (range(1, 300) as $n) {
            WatchedInbox::arrive('Sender '.$n, "s{$n}@example.com", 'Subject '.$n);
        }

        $this->travelTo(now()->addDays(2));
        $this->sweep();

        $this->assertSame(0, $this->arrivals($t['org']),
            'Two days of unwatched mail was announced at once as though it had just arrived.');

        $this->assertSame('311', $this->reload($t['org'], $t['mailbox'])->watch_mark);

        // And it resumes normally from there — the re-mark must not leave the
        // watcher permanently mute.
        WatchedInbox::arrive('Ada', 'ada@example.com', 'After the gap');

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertSame(1, $this->arrivals($t['org']));
    }

    /**
     * A backlog is drained in batches, and every message is announced once.
     *
     * The cap exists so that a mailshot does not insert hundreds of outbox rows
     * in one transaction. What must survive it is the count: five messages, a
     * batch of two, and five events at the end — not four, and not seven.
     */
    public function test_a_backlog_is_drained_in_batches_without_losing_or_repeating_any(): void
    {
        config(['ezbizi.mail_watch.per_poll' => 2]);

        $t = $this->watchedTenant();

        WatchedInbox::fill(3);
        $this->sweep();

        foreach (range(1, 5) as $n) {
            WatchedInbox::arrive('Sender '.$n, "s{$n}@example.com", "Subject {$n}");
        }

        foreach ([2, 4, 5, 5] as $expected) {
            $this->travelTo(now()->addMinutes(6));
            $this->sweep();

            $this->assertSame($expected, $this->arrivals($t['org']));
        }

        $subjects = $this->inTenant($t['org'], fn () => AutomationEvent::where('event_key', 'email.received')
            ->orderBy('occurred_at')->get()->map(fn ($e) => $e->payload['subject'])->all());

        $this->assertSame(
            ['Subject 1', 'Subject 2', 'Subject 3', 'Subject 4', 'Subject 5'],
            $subjects,
            'The batches were announced out of order, or one was announced twice.',
        );
    }

    // ═════════════════════════════════════════════════════════════
    //  REQUIREMENT 3 — no message content is stored
    // ═════════════════════════════════════════════════════════════

    /**
     * The event carries headers, and the message text reaches nothing.
     *
     * Asserted from both directions, because only one of them catches a field
     * added later: the keys that must be there, and the TEXT that must not
     * appear anywhere in the row however it got there.
     */
    public function test_the_event_carries_headers_and_never_a_word_of_the_message(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::arrive(
            'Bob Smith',
            'bob@example.com',
            'Quote for two flues',
            snippet: 'SECRETPREVIEWTEXT the customer typed',
        );

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $event = $this->inTenant($t['org'],
            fn () => AutomationEvent::where('event_key', 'email.received')->firstOrFail());

        $this->assertSame('info@a-1chimney.com', $event->payload['address']);
        $this->assertSame('Quote for two flues', $event->payload['subject']);
        $this->assertSame('Bob Smith', $event->payload['from_name']);
        $this->assertSame('bob@example.com', $event->payload['from_address']);
        $this->assertArrayHasKey('message_id', $event->payload);
        $this->assertArrayHasKey('received_at', $event->payload);

        $this->assertStringNotContainsString('SECRETPREVIEWTEXT', json_encode($event->getAttributes()),
            'The message preview reached the outbox, which is durable for three months and '
            .'readable by tenant-authored rules.');

        $this->assertArrayNotHasKey('snippet', $event->payload);
        $this->assertArrayNotHasKey('body', $event->payload);

        // And the subject is the mailbox, so a franchise-scoped rule can be
        // matched against the inbox the mail arrived at.
        $this->assertSame(Mailbox::class, $event->subject_type);
        $this->assertSame($t['mailbox']->getKey(), $event->subject_id);
    }

    /**
     * THE CASE THIS EXISTS FOR, and the one that would have shipped broken.
     *
     * A-1's RingCentral notifications carry "Status: Missed" at roughly
     * character 199 of the body. The subject never says it, no header says it,
     * and the snippet stops at 160 -- so the word is reachable only by reading
     * the body.
     *
     * The snippet here is deliberately INNOCENT of the word. A matcher that
     * tested the snippet would pass every other assertion in this file and fail
     * this one, which is the whole point: on Gmail the sweep lists with
     * `format=metadata` and never holds the body, so the snippet version would
     * have looked built and matched nothing in production.
     */
    public function test_a_rules_own_word_found_in_the_body_is_named_on_the_event(): void
    {
        $t = $this->watchedTenant();
        $this->inTenant($t['org'], fn () => $this->ruleOnBody($t['org'], $t['admin']->id, ['Missed']));

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::arrive(
            'RingCentral',
            'notify@ringcentral.com',
            'New Call from CBUS',
            snippet: 'You have a new call notification from RingCentral for',
            body: 'You have a new call notification. Caller: (614) 555 0100. Status: Missed.',
        );

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $event = $this->inTenant($t['org'],
            fn () => AutomationEvent::where('event_key', 'email.received')->latest('occurred_at')->firstOrFail());

        $this->assertSame('Missed', $event->payload['body_matched']);

        // AND STILL NOT THE MESSAGE. The word came from the rule; nothing the
        // customer wrote is here.
        //
        // ── WHY THE NEEDLES BELOW LOOK FUSSY ──────────────────────
        //
        // This read `assertStringNotContainsString('614', $json)` until
        // 2026-09-17, and it was passing BY COINCIDENCE. `getAttributes()`
        // carries the row's five UUIDs, a UUID is hexadecimal, and `614` is
        // three hexadecimal characters -- so the assertion failed on whichever
        // runs an identifier happened to spell it and passed on the rest. That
        // is the worse direction: every green run was a fact about random
        // identifiers, and a real leak of the caller's number would have been
        // caught only on the runs where no UUID rescued it. Found by the deploy
        // desk on a failing run that read as a flake.
        //
        // So the sweep is done twice, because the two find different things.
        $payload = json_encode($event->payload);
        $row = json_encode($event->getAttributes());

        // THE CONTROL, and the point the Reports session made today: a negative
        // assertion is satisfied perfectly by an empty world. If `payload` came
        // back null or `getAttributes()` empty, every line below would pass
        // while proving nothing at all.
        $this->assertStringContainsString('Missed', $payload);
        $this->assertStringContainsString('email.received', $row);

        // 1. Against the PAYLOAD, which holds no identifiers, so the number
        //    itself is a safe needle. This is where a leak would actually land.
        $this->assertStringNotContainsString('614', $payload);
        $this->assertStringNotContainsString('555', $payload);

        // 2. Across the WHOLE ROW, which is what would catch a leak into a
        //    column nobody thought about -- and therefore needs needles
        //    hexadecimal cannot spell. A space, a bracket and two letters
        //    outside a-f between them rule out every UUID in the row.
        $this->assertStringNotContainsString('(614)', $row);
        $this->assertStringNotContainsString('555 0100', $row);
        $this->assertStringNotContainsString('Caller', $row);
        $this->assertStringNotContainsString('Status:', $row);
        $this->assertArrayNotHasKey('snippet', $event->payload);
        $this->assertArrayNotHasKey('body', $event->payload);
    }

    /**
     * No rule asking, no read, and the field still exists.
     *
     * Both halves matter. Nothing is fetched or stored when nobody has asked --
     * and the key is present anyway, because the condition picker offers fields
     * it has SEEN on an event. A key that appeared only once a rule used it
     * could never be picked to write that rule in the first place.
     */
    public function test_with_no_rule_nothing_is_read_and_the_field_is_still_offered(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::arrive(
            'RingCentral',
            'notify@ringcentral.com',
            'New Call from CBUS',
            body: 'Status: Missed.',
        );

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $event = $this->inTenant($t['org'],
            fn () => AutomationEvent::where('event_key', 'email.received')->latest('occurred_at')->firstOrFail());

        $this->assertArrayHasKey('body_matched', $event->payload);
        $this->assertSame('', $event->payload['body_matched']);

        // The body was never opened: no rule asked, so no second fetch happened.
        $this->assertNotContains('open', WatchedInbox::$calls);
    }

    /**
     * A word in the body that no rule asked about is not reported.
     *
     * The control for the test above it. Without this, a payload that echoed
     * the whole body would pass "Missed is named" and be the very leak the
     * design exists to avoid.
     */
    public function test_only_the_words_a_rule_asked_about_are_named(): void
    {
        $t = $this->watchedTenant();
        $this->inTenant($t['org'], fn () => $this->ruleOnBody($t['org'], $t['admin']->id, ['Missed']));

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::arrive(
            'RingCentral',
            'notify@ringcentral.com',
            'New Call',
            body: 'Status: Answered. Duration: 4 minutes. Voicemail left.',
        );

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $event = $this->inTenant($t['org'],
            fn () => AutomationEvent::where('event_key', 'email.received')->latest('occurred_at')->firstOrFail());

        $this->assertSame('', $event->payload['body_matched']);
        $this->assertStringNotContainsString('Answered', json_encode($event->getAttributes()));
        $this->assertStringNotContainsString('Voicemail', json_encode($event->getAttributes()));
    }

    /**
     * A poll is not an EDIT, and `updated_at` must not say it was.
     *
     * That column answers "who last changed this mailbox, and when" — it is what
     * an administrator reads and what the audit trail is compared against. A
     * timer touching every mailbox every five minutes would rewrite it on every
     * row in the system, permanently, and the one fact it carries would be gone.
     *
     * Easy to break in two places and invisible in both: the claim is a query
     * builder update, which Eloquent silently stamps unless it is taken down to
     * the base builder, and the save afterwards would stamp it too unless
     * timestamps are switched off around it. Both are asserted here by one
     * comparison, because both produce the identical symptom.
     */
    public function test_watching_a_mailbox_does_not_look_like_somebody_editing_it(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(2);

        $before = $this->reload($t['org'], $t['mailbox'])->updated_at;

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        WatchedInbox::arrive('Bob', 'bob@example.com', 'Hello');

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $after = $this->reload($t['org'], $t['mailbox']);

        $this->assertSame(1, $this->arrivals($t['org']), 'The fixture did not actually poll.');
        $this->assertTrue($before->equalTo($after->updated_at),
            'Polling moved updated_at, so every mailbox in the system now reads as edited '
            .'minutes ago and nobody can tell who really changed one.');

        // The columns that SHOULD have moved did, so this is not passing because
        // the sweep wrote nothing at all.
        $this->assertNotNull($after->watch_marked_at);
        $this->assertNotNull($after->last_reached_at);
    }

    /** Nothing anywhere started a messages table. */
    public function test_watching_stores_no_message_rows_of_any_kind(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(5);
        $this->sweep();

        WatchedInbox::arrive('Bob', 'bob@example.com', 'Hello');

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        // `message_seen` is the one table keyed by a provider message id, and it
        // records that a PERSON opened something. A watcher must not write to it
        // — that would make every message read by nobody.
        $this->inTenant($t['org'], fn () => $this->assertSame(0, \App\Models\MessageSeen::count()));
    }

    // ═════════════════════════════════════════════════════════════
    //  REQUIREMENT 4 — tenancy, with no request
    // ═════════════════════════════════════════════════════════════

    /**
     * Two organizations, two inboxes, and neither one's mail reaches the other.
     *
     * Asserted three ways, because each catches a different mistake: the counts
     * per organization, the `organization_id` stamped on every event, and the
     * tenant that was actually entered at the moment the mail service was
     * asked — which is the one that catches a sweep that reads the right rows
     * while standing in the wrong tenant.
     */
    public function test_an_arrival_in_one_organization_is_never_raised_in_another(): void
    {
        $a = $this->watchedTenant('A1-Group', 'info@a1.test');
        $b = $this->watchedTenant('Beta-Group', 'info@beta.test');

        WatchedInbox::fill(3);
        $this->sweep();

        WatchedInbox::arriveFor('info@a1.test', 'Bob', 'bob@example.com', 'A1 only');

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertSame(1, $this->arrivals($a['org']));
        $this->assertSame(0, $this->arrivals($b['org']),
            'An arrival at one organization\'s inbox raised an event in another organization.');

        $this->inTenant($a['org'], function () use ($a) {
            $event = AutomationEvent::where('event_key', 'email.received')->firstOrFail();

            $this->assertSame($a['org']->id, $event->organization_id);
            $this->assertSame('info@a1.test', $event->payload['address']);
        });

        // Every call to the mail service happened inside the tenant that owns
        // the mailbox. A sweep that polled correctly while standing in the wrong
        // organization would pass the counts above and fail here.
        foreach (WatchedInbox::$calls as [$address, $tenantId]) {
            $expected = $address === 'info@a1.test' ? $a['org']->id : $b['org']->id;

            $this->assertSame($expected, $tenantId,
                "The mail service was asked about {$address} while acting as another organization.");
        }
    }

    /**
     * A branch's rule covers its own inbox, and not the branch next door's.
     *
     * ── Why this is asserted here rather than assumed ────────────
     *
     * The engine works out which rules an event concerns by asking the SUBJECT
     * where it sits, and its fallback is to sniff for a `franchise_id` column. A
     * mailbox has no such column — it carries §3's `scope_type`/`scope_id` pair,
     * like a sending identity — so before {@see Mailbox::automationScope} every
     * event about a mailbox arrived claiming to belong to the whole
     * organization, and a branch manager's rule matched nothing at all for the
     * life of the rule with nothing anywhere saying why.
     *
     * Composed from the two real pieces — what the mailbox answers, and the
     * predicate the engine actually applies — rather than asserting the return
     * value on its own, because a scope that is reported correctly and then
     * compared wrongly is the same bug with a passing unit test in front of it.
     */
    public function test_a_franchise_scoped_inbox_is_covered_by_its_own_branchs_rule_only(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => Mailbox::SCOPE_FRANCHISE,
            'scope_id'        => $t['franchise']->id,
            'address'         => 'branch@a1.test',
            'display_name'    => 'Branch',
            'provider'        => 'gmail_oauth',
        ]));

        [$scopeType, $scopeId] = $mailbox->automationScope();

        $ownBranch = new Automation(['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id]);
        $nextDoor  = new Automation(['scope_type' => 'franchise', 'scope_id' => 'another-branch']);
        $wholeOrg  = new Automation(['scope_type' => null, 'scope_id' => null]);

        $this->assertTrue($ownBranch->coversScope($scopeType, $scopeId),
            'A branch rule did not cover mail arriving at that branch\'s own inbox.');

        $this->assertFalse($nextDoor->coversScope($scopeType, $scopeId),
            'One branch\'s rule fired on another branch\'s customer mail, handing them the '
            .'sender and subject of every message in it.');

        $this->assertTrue($wholeOrg->coversScope($scopeType, $scopeId));

        // And an organization-wide inbox belongs to the organization — it does
        // NOT cascade down to every branch. Mailboxes are the one thing in §3
        // that deliberately does not, so that a branch cannot reach the
        // company's customer mail.
        $companyWide = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => Mailbox::SCOPE_ORGANIZATION,
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a1.test',
            'display_name'    => 'Info',
            'provider'        => 'gmail_oauth',
        ]));

        $this->assertSame([null, null], $companyWide->automationScope());
        $this->assertFalse($ownBranch->coversScope(...$companyWide->automationScope()));
    }

    /** Every event, whatever happens, belongs to the mailbox's own organization. */
    public function test_the_watcher_refuses_to_run_without_a_tenant(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);

        $this->expectException(\RuntimeException::class);

        // No TenantContext::run around it. A scheduled command has no request,
        // so this is the state the whole feature starts in, and failing closed
        // here is what stops a poll ever running unscoped.
        app(MailboxWatcher::class)->poll($t['mailbox']);
    }

    // ═════════════════════════════════════════════════════════════
    //  REQUIREMENT 5 — quota and politeness
    // ═════════════════════════════════════════════════════════════

    /** A mailbox looked at a minute ago is not looked at again. */
    public function test_a_mailbox_is_not_polled_again_before_its_cadence_has_passed(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();

        $calls = count(WatchedInbox::$calls);

        $this->travelTo(now()->addMinute());
        $this->sweep();

        $this->assertCount($calls, WatchedInbox::$calls,
            'The mail service was asked again a minute later, which at a hundred mailboxes is '
            .'five times the traffic the cadence was chosen to produce.');

        $this->travelTo(now()->addMinutes(5));
        $this->sweep();

        $this->assertGreaterThan($calls, count(WatchedInbox::$calls),
            'The mailbox was never polled again at all.');
    }

    /**
     * A SLOW poll waits a full cadence after it FINISHED, not after it started.
     *
     * The claim marks a mailbox due again one cadence after it was taken, which
     * is right for the common case and wrong for the case politeness is about: a
     * mail server that takes four minutes to answer would otherwise be dialled
     * again sixty seconds later, and a server already struggling is exactly the
     * one that must not be asked more often.
     *
     * The clock is moved by the fake reader rather than by a real wait — a test
     * that slept for four minutes would be a test nobody runs.
     */
    public function test_a_slow_poll_waits_a_full_cadence_after_it_finished(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::$takesSeconds = 240;

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $mailbox = $this->reload($t['org'], $t['mailbox']);

        $this->assertTrue($mailbox->watch_next_poll_at->gt(now()->addMinutes(4)),
            'A poll that took four minutes left the mailbox due again a minute later, so a '
            .'mail server that is already slow gets asked five times as often as a healthy one.');
    }

    /** A broken mailbox is not dialled — somebody has to reconnect it. */
    public function test_a_broken_mailbox_is_not_dialled(): void
    {
        $t = $this->watchedTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::findOrFail($t['mailbox']->getKey());
            $mailbox->markBroken('The sign-in was refused.');
            $mailbox->save();
        });

        $this->sweep();

        $this->assertSame([], WatchedInbox::$calls,
            'A mailbox already known to be broken was dialled anyway, which at five minutes a '
            .'time is a failing connection to somebody\'s mail server for as long as nobody '
            .'notices.');
    }

    /** Nor one nobody has finished connecting. */
    public function test_a_mailbox_nobody_has_connected_is_not_dialled(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::$unavailable = 'Nobody has signed in to Google for this address yet.';

        $this->sweep();

        $this->assertSame([], WatchedInbox::$calls);

        $mailbox = $this->reload($t['org'], $t['mailbox']);

        $this->assertNull($mailbox->watch_mark);
        $this->assertSame(0, $mailbox->watch_failures,
            'An inbox nobody has connected was counted as failing, which would back it off for '
            .'hours once somebody finally connected it.');
        $this->assertNotNull($mailbox->watch_next_poll_at,
            'It was left permanently due, so it would be reconsidered every single minute.');
    }

    /**
     * A poll that blows up in an unexpected way still gives the mailbox a rest.
     *
     * This is what the CLAIM is for, and nothing else covers it. Every failure
     * the mail service can produce is caught and backed off; what is left is a
     * bug — a provider string this build does not recognise, a database hiccup —
     * and the sweep catches those so that one bad mailbox cannot stop the other
     * ninety-nine. Without the claim having already pushed the due time forward,
     * that mailbox would still be due, and the next sweep a minute later would
     * hit the same bug, for ever, at the front of the queue.
     */
    public function test_a_poll_that_fails_unexpectedly_still_stops_the_mailbox_being_hammered(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::$failWith = new \RuntimeException('something nobody predicted');

        $this->sweep();

        $mailbox = $this->reload($t['org'], $t['mailbox']);

        $this->assertNotNull($mailbox->watch_next_poll_at);
        $this->assertTrue($mailbox->watch_next_poll_at->gt(now()->addMinutes(4)),
            'A mailbox whose poll crashed was left due immediately, so the next sweep a minute '
            .'later will hit the same crash — and every sweep after that.');
    }

    /** A failure that is not the mailbox breaking waits longer each time. */
    public function test_a_rate_limit_backs_off_and_does_not_mark_the_inbox_broken(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::$failWith = CannotReadMailbox::tooManyRequests($t['mailbox']);

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $mailbox = $this->reload($t['org'], $t['mailbox']);

        $this->assertSame(Mailbox::STATUS_CONNECTED, $mailbox->status,
            'Being asked to slow down was recorded as the inbox being broken.');
        $this->assertSame(1, $mailbox->watch_failures);
        $this->assertTrue($mailbox->watch_next_poll_at->gt(now()->addMinutes(9)),
            'The backoff did not lengthen, so a service asking us to slow down is asked again '
            .'at exactly the same rate.');
    }

    /** A failure that IS the mailbox breaking takes it out of the sweep. */
    public function test_a_refused_sign_in_marks_the_inbox_broken_and_stops_the_polling(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::$failWith = CannotReadMailbox::credentialRejected($t['mailbox']);

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $mailbox = $this->reload($t['org'], $t['mailbox']);

        $this->assertSame(Mailbox::STATUS_BROKEN, $mailbox->status);

        // And the alert reached the outbox — through the SAME wrapper the
        // screens use, which is why the watcher has no health code of its own.
        $this->assertSame(1, $this->inTenant($t['org'],
            fn () => AutomationEvent::where('event_key', 'email.mailbox_broken')->count()));

        // From here on it is not dialled again at all.
        $calls = count(WatchedInbox::$calls);

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertCount($calls, WatchedInbox::$calls);
    }

    /**
     * Reconnecting is checked within the minute (Jose 11-12: "doing a
     * connection should take care of the whole process"). The backoff a broken
     * inbox built up -- up to six hours -- does not outlive the fix.
     */
    public function test_reconnecting_a_broken_inbox_polls_it_on_the_next_sweep(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();
        WatchedInbox::$failWith = CannotReadMailbox::tooManyRequests($t['mailbox']);
        for ($i = 0; $i < 4; $i++) {
            $this->travelTo(now()->addHours(2));
            $this->sweep();
        }
        $this->assertTrue($this->reload($t['org'], $t['mailbox'])->watch_next_poll_at->gt(now()->addMinutes(30)),
            'The setup did not build a backoff, so the test proves nothing.');

        WatchedInbox::$failWith = null;
        $this->inTenant($t['org'], function () use ($t) {
            $mailbox = $this->reload($t['org'], $t['mailbox']);
            $mailbox->connect('new-token');
            $mailbox->save();
        });

        $calls = count(WatchedInbox::$calls);
        $this->travelTo(now()->addMinute());
        $this->sweep();

        $this->assertGreaterThan($calls, count(WatchedInbox::$calls));
        $this->assertSame(0, $this->reload($t['org'], $t['mailbox'])->watch_failures);
    }

    /** An organization that has stopped paying is not polled (§13). */
    public function test_a_lapsed_organization_is_not_polled(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);

        // The state §13 describes: still there, still readable, no longer
        // writable. Organization::isReadOnly() is what every write path already
        // consults, and the watcher consults the same thing rather than a second
        // opinion about what "lapsed" means.
        $t['org']->forceFill(['is_active' => false])->save();
        \App\Support\Subscriptions\SubscriptionGuard::flush();

        $this->sweep();

        $this->assertSame([], WatchedInbox::$calls,
            'An organization whose subscription has ended was still having its mail service '
            .'polled, which is the product working fine without paying.');
    }

    // ═════════════════════════════════════════════════════════════
    //  REQUIREMENT 6 — it can be turned off
    // ═════════════════════════════════════════════════════════════

    /** The deployment switch stops everything, and says so. */
    public function test_the_installation_switch_stops_every_poll(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);

        config(['ezbizi.mail_watch.enabled' => false]);

        $this->artisan('ezbizi:watch-mailboxes')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        $this->assertSame([], WatchedInbox::$calls);
        $this->assertNull($this->reload($t['org'], $t['mailbox'])->watch_mark);
    }

    /**
     * The tenant's switch is the rule itself, and it works in both directions.
     *
     * An organization that has built no rule on this trigger has asked for
     * nothing, so its mail service is never touched. This is the largest saving
     * in the design and it is also the only tenant-facing control — a separate
     * setting could disagree with the rule, and a rule with no polling behind it
     * is the exact failure `isRaised: false` exists to warn about.
     */
    public function test_an_organization_with_no_live_rule_is_never_polled(): void
    {
        $t = $this->provisionTenant('Quiet-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => 'info@quiet.test',
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);

            $mailbox->connect('a-token');
            $mailbox->save();
        });

        WatchedInbox::fill(1);

        $this->sweep();

        $this->assertSame([], WatchedInbox::$calls,
            'An organization with no rule listening for arrivals had its mail service polled '
            .'anyway, spending somebody\'s quota to answer a question nobody asked.');

        // Switch a rule on and the very next sweep marks it.
        $this->inTenant($t['org'], fn () => $this->listenFor($t['org'], $t['admin']->id));

        $this->sweep();

        $this->assertNotSame([], WatchedInbox::$calls,
            'Switching a rule on did not start the watching.');
    }

    /** A rule switched off stops the polling with it. */
    public function test_switching_the_rule_off_stops_the_polling(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();

        $this->inTenant($t['org'], fn () => Automation::query()->update(['is_active' => false]));

        $calls = count(WatchedInbox::$calls);

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertCount($calls, WatchedInbox::$calls);
    }

    // ═════════════════════════════════════════════════════════════
    //  The declaration, which is now a promise about this file
    // ═════════════════════════════════════════════════════════════

    /**
     * The catalogue no longer tells a tenant the rule will never run.
     *
     * ModuleRegistryTest already fails the build if `isRaised` disagrees with
     * the source. What it cannot check is the PROSE, and the prose is what
     * somebody actually reads: a description explaining at length that nothing
     * watches the inboxes would be exactly the R15 failure this project keeps
     * finding, sitting on a screen rather than in a comment.
     */
    public function test_the_trigger_no_longer_says_that_nothing_watches_the_inboxes(): void
    {
        $trigger = collect(app(ModuleRegistry::class)->get('email')->triggers)
            ->first(fn (TriggerDefinition $t) => $t->key === 'email.received');

        $this->assertNotNull($trigger);
        $this->assertTrue($trigger->isRaised);

        $this->assertStringNotContainsString(TriggerDefinition::NOT_RAISED_YET, $trigger->description);
        $this->assertStringNotContainsString('Nothing watches', $trigger->description);

        // And it warns about the two things somebody would otherwise report as
        // bugs: the delay, and the silence on a newly connected inbox.
        $this->assertStringContainsString('every few minutes', $trigger->description);
        $this->assertStringContainsString('raises nothing for the mail already in it', $trigger->description);
    }
    // ── {{description}}: Jose, 2026-10-06 ──────────────────────────

    /**
     * "Email notification: From Name / Subject / Description up to 178
     * characters." A rule that writes {{description}} gets the body as plain
     * text, clipped to 178 with the ellipsis counted, and NOTHING past it.
     */
    public function test_a_rule_writing_description_gets_178_characters_of_the_body(): void
    {
        $t = $this->watchedTenant();
        $this->inTenant($t['org'], fn () => $this->ruleWriting($t['org'], $t['admin']->id, '{{from_name}}: {{subject}}'));

        WatchedInbox::fill(1);
        $this->sweep();

        WatchedInbox::arrive('Ana Reis', 'ana@example.com', 'Quote request', body: 'Hello, I would like a quote for sweeping two chimneys at my house on Elm Street. Hello, I would like a quote for sweeping two chimneys at my house on Elm Street. Hello, I would like a quote for sweeping two chimneys at my house on Elm Street. Hello, I would like a quote for sweeping two chimneys at my house on Elm Street. '.'TAILMARKER');

        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $description = $this->latestArrival($t['org'])->payload['description'];

        // UP TO 178, as Jose put it: the shared clip trims a space it would
        // otherwise end on before adding the ellipsis, so 177 is as right as
        // 178. At least 170 proves it was not cut short somewhere else.
        $this->assertLessThanOrEqual(178, mb_strlen($description));
        $this->assertGreaterThanOrEqual(170, mb_strlen($description));
        $this->assertStringStartsWith('Hello, I would like a quote', $description);
        $this->assertStringEndsWith('…', $description);
        $this->assertStringNotContainsString(
            'TAILMARKER',
            json_encode($this->latestArrival($t['org'])->getAttributes()),
            'More than the 178 characters reached the stored event.',
        );
    }

    /**
     * THE CONTROL, and the privacy property: with no rule writing it, no body is
     * opened at all and the field is present but empty.
     */
    public function test_no_rule_writing_description_reads_no_body(): void
    {
        $t = $this->watchedTenant();
        // A rule exists, but its step never asks for the preview.
        $this->inTenant($t['org'], fn () => $this->ruleWriting($t['org'], $t['admin']->id, 'New mail from {{from_name}}'));
        $this->inTenant($t['org'], fn () => AutomationVersion::query()->update([
            'actions' => [['type' => 'notify', 'message' => 'New mail from {{from_name}}']],
        ]));

        WatchedInbox::fill(1);
        $this->sweep();
        WatchedInbox::arrive('Ana Reis', 'ana@example.com', 'Quote request', body: 'A private message.');
        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertSame(0, WatchedInbox::$opened, 'A body was opened that no rule asked for.');
        $this->assertSame('', $this->latestArrival($t['org'])->payload['description']);
    }

    /** A rule that is switched off is not asking. */
    public function test_a_switched_off_rule_writing_description_reads_no_body(): void
    {
        $t = $this->watchedTenant();
        $this->inTenant($t['org'], fn () => $this->ruleWriting($t['org'], $t['admin']->id, '{{description}}', active: false));

        WatchedInbox::fill(1);
        $this->sweep();
        WatchedInbox::arrive('Ana Reis', 'ana@example.com', 'Quote request', body: 'A private message.');
        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertSame(0, WatchedInbox::$opened);
        $this->assertSame('', $this->latestArrival($t['org'])->payload['description']);
    }

    /**
     * `is_bulk` reaches the event both ways round, so a rule can keep to mail a
     * person wrote. Both in one test: a key that was always false, or always
     * true, fails one of the two.
     */
    public function test_the_event_says_whether_the_mail_was_bulk(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();
        WatchedInbox::arrive('Ana Reis', 'ana@example.com', 'Quote request');
        WatchedInbox::arrive('RingCentral', 'notify@ringcentral.com', 'New call', bulk: true);
        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $bulk = $this->inTenant($t['org'], fn () => AutomationEvent::where('event_key', 'email.received')
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->mapWithKeys(fn ($e) => [$e->payload['from_address'] => $e->payload['is_bulk']])
            ->all());

        $this->assertSame(false, $bulk['ana@example.com']);
        $this->assertSame(true, $bulk['notify@ringcentral.com']);
    }

    /** "{{from_name}}: {{subject}}" never reads ": Quote request". */
    public function test_from_name_falls_back_to_the_address(): void
    {
        $t = $this->watchedTenant();

        WatchedInbox::fill(1);
        $this->sweep();
        WatchedInbox::arrive('', 'ana@example.com', 'Quote request');
        $this->travelTo(now()->addMinutes(6));
        $this->sweep();

        $this->assertSame('ana@example.com', $this->latestArrival($t['org'])->payload['from_name']);
    }
}

/**
 * A mail service, modelled the way the harder of the two providers works.
 *
 * ── Positions, not subjects ──────────────────────────────────────
 *
 * Messages have integer positions, a mark IS a position, and asking for arrivals
 * from a position returns what sits at or after it. That is IMAP's UID model,
 * and Gmail's history id behaves the same way from the caller's side. Building
 * the fake this way rather than as "return whatever the test queued" is what
 * makes the mark assertions mean anything: a watcher that never advanced the
 * mark would keep passing a queue-shaped fake and fails immediately against
 * this one.
 *
 * Static, because the container resolves the reader inside the command and a
 * test has to arrange the mailbox before the sweep runs. Reset in setUp.
 */
class WatchedInbox implements MailboxReader
{
    /** @var array<string, array<int, MessageSummary>> address => position => message */
    public static array $mailboxes = [];

    /** Every call that reached the mail service: [address, tenant id at the time]. */
    public static array $calls = [];

    public static ?Throwable $failWith = null;

    public static ?string $unavailable = null;

    /** Model an expired history id / changed UIDVALIDITY on the next arrivals call. */
    public static bool $rejectTheMark = false;

    /**
     * How long the mail service takes to answer.
     *
     * Moved on the TEST CLOCK rather than slept, so "a mail server that takes
     * four minutes" costs the suite nothing and is exactly reproducible. A real
     * sleep here would be four minutes per run and the first thing anybody
     * deleted.
     */
    public static int $takesSeconds = 0;

    private static int $nextId = 1;

    /** How many message bodies were opened. */
    public static int $opened = 0;

    public static function reset(): void
    {
        self::$opened       = 0;
        self::$mailboxes    = [];
        self::$calls        = [];
        self::$failWith     = null;
        self::$unavailable  = null;
        self::$rejectTheMark = false;
        self::$takesSeconds = 0;
        self::$nextId       = 1;
        self::$bodies       = [];
    }

    /** Mail that was ALREADY THERE when the mailbox was connected. */
    public static function fill(int $count): void
    {
        foreach (range(1, max(0, $count)) as $ignored) {
            self::arriveFor('*', 'Older', 'older@example.com', 'Already here');
        }
    }

    public static function arrive(
        string $name,
        string $address,
        string $subject,
        string $snippet = '',
        string $body = '',
        bool $bulk = false,
    ): void {
        self::arriveFor('*', $name, $address, $subject, $snippet, $body, $bulk);
    }

    /**
     * `*` is mail every inbox has; an address is mail only that one has.
     *
     * Every mailbox's contents are the shared pile followed by its own, so a
     * single-tenant test can say "three messages were already there" without
     * naming an address, and a two-tenant test can put one message in exactly
     * one of the two inboxes and prove it stayed there.
     *
     * The shared pile must be filled BEFORE any per-address arrival, or the
     * positions of the per-address ones move under the mark. Every test here
     * does; a test that did not would be modelling a mail server that inserts
     * messages into the middle of a mailbox, which none does.
     */
    public static function arriveFor(
        string $mailbox,
        string $name,
        string $address,
        string $subject,
        string $snippet = '',
        string $body = '',
        bool $bulk = false,
    ): void {
        $id = 'msg-'.self::$nextId++;

        // The BODY is stored apart from the summary, exactly as a real provider
        // holds it: a list gives headers and a snippet, and the body costs a
        // second fetch. A fake that returned the body from `list` would let a
        // matcher read it there and pass, then find nothing in production.
        self::$bodies[$id] = $body;

        self::$mailboxes[$mailbox][] = new MessageSummary(
            id: $id,
            threadId: null,
            subject: $subject,
            fromName: $name,
            fromAddress: $address,
            to: [],
            sentAt: Carbon::parse('2026-08-13 09:00:00'),
            snippet: $snippet,
            isBulk: $bulk,
        );
    }

    /** @var array<string, string> message id => body text */
    public static array $bodies = [];

    /** @return MessageSummary[] oldest first, position = index + 1 */
    private function contents(Mailbox $mailbox): array
    {
        return array_merge(
            self::$mailboxes['*'] ?? [],
            self::$mailboxes[$mailbox->address] ?? [],
        );
    }

    private function reached(Mailbox $mailbox): void
    {
        self::$calls[] = [$mailbox->address, app(TenantContext::class)->id()];

        if (self::$takesSeconds > 0) {
            Carbon::setTestNow(Carbon::now()->addSeconds(self::$takesSeconds));
        }

        if (self::$failWith !== null) {
            throw self::$failWith;
        }
    }

    public function currentMark(Mailbox $mailbox): string
    {
        $this->reached($mailbox);

        return (string) (count($this->contents($mailbox)) + 1);
    }

    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals
    {
        $this->reached($mailbox);

        $contents = $this->contents($mailbox);

        if (self::$rejectTheMark) {
            return new Arrivals([], (string) (count($contents) + 1), markWasStale: true);
        }

        $from  = max(1, (int) $mark);
        $batch = array_slice($contents, $from - 1, $limit);

        return new Arrivals($batch, (string) ($from + count($batch)));
    }

    // ── the rest of the interface, which this file is not about ──

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return new MessagePage($this->contents($mailbox));
    }

    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return new MessagePage([]);
    }

    public function open(Mailbox $mailbox, string $messageId, ?string $threadId = null): MessageDetail
    {
        self::$opened++;
        $summary = null;

        foreach ($this->contents($mailbox) as $row) {
            if ($row->id === $messageId) {
                $summary = $row;
                break;
            }
        }

        $summary ??= $this->contents($mailbox)[0];
        $body = WatchedInbox::$bodies[$messageId] ?? '';

        return new MessageDetail(
            $summary,
            $body === '' ? [] : [Block::paragraph([new Span($body)])],
        );
    }

    public function reply(Mailbox $mailbox, string $messageId, string $body): void {}

    public function send(Mailbox $mailbox, array $to, string $subject, string $body, array $attachments = []): void {}

    public function availableFolders(Mailbox $mailbox): array
    {
        return ['INBOX'];
    }

    public function canMarkReadAtSource(Mailbox $mailbox): bool
    {
        return false;
    }

    public function markReadAtSource(Mailbox $mailbox, string $messageId): void {}

    public function markUnreadAtSource(Mailbox $mailbox, string $messageId): void {}

    public function trash(Mailbox $mailbox, string $messageId): void {}

    public function downloadAttachment(Mailbox $mailbox, string $messageId, string $attachmentId): EmailPicture
    {
        return new EmailPicture('x', 'image/png');
    }

    public function isConfigured(Mailbox $mailbox): bool
    {
        return self::$unavailable === null;
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        return self::$unavailable;
    }

    public function notReadyBecause(): ?string
    {
        return null;
    }

    public function unreadCount(Mailbox $mailbox): int
    {
        return 0;
    }
}

/**
 * Substitutes the MAIL SERVICE and nothing else.
 *
 * `readerFor` and not `for`: the latter is final, and it is what wraps the
 * reader in RecordingMailboxReader. Overriding it would substitute away the
 * health recording — which two tests in this file are specifically about, since
 * the whole point of routing the poller through that wrapper is that it gets
 * `last_reached_at` and `email.mailbox_broken` without a second health path.
 */
class WatchedInboxes extends MailboxReaders
{
    protected function readerFor(Mailbox $mailbox): MailboxReader
    {
        return new WatchedInbox;
    }
}
