<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Role;
use App\Support\Email\Arrivals;
use App\Support\Email\Block;
use App\Support\Email\CannotReachHost;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\GmailMailboxReader;
use App\Support\Email\MailboxFault;
use App\Support\Email\MailboxReader;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MessageDetail;
use App\Support\Email\MessagePage;
use App\Support\Email\MessageSummary;
use App\Support\Email\RecordingMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Throwable;

/**
 * "When did this inbox stop working, and why?"
 *
 * The owner asked for a settings screen that *"should definitely display the
 * message of revoked or expired"*. Two things had to become true before such a
 * screen could say anything worth reading, and this file is about both.
 *
 * ── 1. The timestamp has to be TRUSTWORTHY ───────────────────────
 *
 * `last_reached_at` is worth nothing unless it moves in exactly one situation.
 * Three tests below try to move it wrongly — by attempting a read that fails, by
 * drawing the settings list, and by asking whether a mailbox is configured — and
 * every one of them asserts it did not budge. Those are the assertions that make
 * the fourth one, where a successful read does move it, mean anything at all.
 *
 * ── 2. The sentence has to name a CAUSE ──────────────────────────
 *
 * A `status_reason` reading "Client error: `POST https://oauth2.googleapis.com`"
 * is a screen that has learned nothing. So the sentence is asserted for the words
 * a person needs — withdrawn, expired, password — and asserted AGAINST the
 * vocabulary of the machine: no class names, no namespaces, no OAuth error codes,
 * no HTTP status numbers.
 *
 * ── What is faked, and what that costs ───────────────────────────
 *
 * There is no Google account on this installation and there cannot be one here,
 * so no test below produces a REAL revoked token. What is exercised instead is
 * the mapping: given the response shape Google documents for each case, does the
 * reader reach the right cause, and does the right cause produce the right
 * sentence and the right decision about marking the row broken. That is the part
 * that is code. Whether Google really answers `invalid_grant` for a withdrawn
 * token is Google's documentation, and no test here can or should assert it.
 */
class MailboxHealthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/email/mailboxes';

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedMailboxReader::reset();
    }

    // ── fixtures ─────────────────────────────────────────────────

    /** @param array{org: \App\Models\Organization} $t */
    private function inbox(array $t, string $address = 'info@a-1chimney.com'): Mailbox
    {
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

            MailboxGrant::create([
                'organization_id' => $t['org']->id,
                'mailbox_id'      => $mailbox->getKey(),
                'role_id'         => Role::where('name', Role::ADMINISTRATOR)->firstOrFail()->getKey(),
            ]);

            return $mailbox;
        });
    }

    /** Swap the provider — and only the provider — for one this test scripts. */
    private function withScriptedProvider(): void
    {
        $this->app->bind(MailboxReaders::class, fn () => new ScriptedMailboxReaders);
    }

    private function open(array $t, Mailbox $mailbox): \Illuminate\Testing\TestResponse
    {
        return $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT."/{$mailbox->id}/messages/m-1");
    }

    private function reload(array $t, Mailbox $mailbox): Mailbox
    {
        return $this->inTenant($t['org'], fn () => Mailbox::findOrFail($mailbox->getKey()));
    }

    // ══ 1. THE TIMESTAMP MOVES ON SUCCESS AND ON NOTHING ELSE ════

    public function test_a_successful_read_records_when_the_inbox_last_answered(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->assertNull($mailbox->last_reached_at, 'A new mailbox has never been reached.');

        $this->open($t, $mailbox)->assertOk();

        $this->assertNotNull(
            $this->reload($t, $mailbox)->last_reached_at,
            'A read that returned a message did not record that the inbox answered.',
        );
    }

    /**
     * THE ASSERTION THE WHOLE COLUMN DEPENDS ON.
     *
     * A timestamp that moves when a read FAILS says an inbox was reached at the
     * exact moment it was not — which is worse than having no column, because it
     * is a wrong answer wearing a right one's clothes. The mailbox here has a
     * time on it already, so this catches both mistakes: stamping a fresh time,
     * and clearing the old one.
     */
    public function test_a_failed_read_does_not_touch_the_timestamp(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        // It worked on Tuesday.
        $tuesday = Carbon::parse('2026-08-11 09:00:00');

        $this->inTenant($t['org'], function () use ($mailbox, $tuesday) {
            $mailbox->forceFill(['last_reached_at' => $tuesday])->save();
        });

        ScriptedMailboxReader::$failWith = CannotReadMailbox::credentialRejected($mailbox);

        $this->open($t, $mailbox)->assertStatus(409);

        $after = $this->reload($t, $mailbox);

        $this->assertTrue(
            $tuesday->equalTo($after->last_reached_at),
            'A failed read moved the "last worked" time, so the screen would say it '
            .'worked at the moment it did not: '.$after->last_reached_at,
        );

        // The CONTROL for the assertion above: the same mailbox, the same route,
        // succeeding, does move it. Without this the test would pass just as
        // happily against a column nothing ever writes.
        ScriptedMailboxReader::$failWith = null;

        $this->open($t, $mailbox)->assertOk();

        $this->assertTrue(
            $this->reload($t, $mailbox)->last_reached_at->greaterThan($tuesday),
            'Nothing moves this column at all, so the failure case proves nothing.',
        );
    }

    /**
     * Opening the settings tab is not the inbox answering.
     *
     * The list endpoint asks every mailbox whether it can be opened, once per
     * row, in order to draw the screen. If that question moved the column, the
     * screen would refresh every row it displayed and the field would answer
     * "when did you last look at this tab" — a question nobody asked.
     *
     * Run against the REAL readers rather than a script, because this is exactly
     * the code path production takes and it opens no socket to do it.
     */
    public function test_drawing_the_settings_list_does_not_record_a_reach(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertNull(
            $this->reload($t, $mailbox)->last_reached_at,
            'Listing the mailboxes recorded them as reached, so the column now answers '
            .'"when did somebody open this screen".',
        );
    }

    /**
     * The same fact one level down, asserted against the wrapper itself.
     *
     * The test above goes through the controller and would keep passing if the
     * controller stopped calling `isConfigured` for some unrelated reason. This
     * one asks the wrapper the question directly, so the guarantee is pinned to
     * the class that makes it.
     */
    public function test_asking_whether_a_mailbox_is_configured_records_nothing(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], function () use ($mailbox) {
            $reader = app(MailboxReaders::class)->for($mailbox);

            $reader->isConfigured($mailbox);
            $reader->unavailableBecause($mailbox);

            $this->assertNull(Mailbox::findOrFail($mailbox->getKey())->last_reached_at);

            // The control: the same reader, asked to actually read.
            $reader->list($mailbox);

            $this->assertNotNull(Mailbox::findOrFail($mailbox->getKey())->last_reached_at);
        });
    }

    /**
     * `updated_at` is what somebody reads to answer "who touched this?".
     *
     * A read is not a change, and letting a page view move that column would
     * make it say the inbox was edited every minute of every working day.
     */
    public function test_recording_a_reach_is_not_recorded_as_somebody_editing_the_mailbox(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $editedAt = $this->reload($t, $mailbox)->updated_at;

        Carbon::setTestNow(now()->addHour());

        $this->open($t, $mailbox)->assertOk();

        $after = $this->reload($t, $mailbox);

        $this->assertTrue($editedAt->equalTo($after->updated_at), 'A read looks like an edit.');
        $this->assertTrue($after->last_reached_at->greaterThan($editedAt), 'Nothing was recorded at all.');

        Carbon::setTestNow();
    }

    // ══ 2. THE SENTENCE NAMES A CAUSE, NOT A CLASS ═══════════════

    /**
     * The owner's ask, answered as honestly as the mail service permits.
     *
     * *"it should definitely display the message of revoked or expired"*. Those
     * two are one case here, because Google answers a withdrawn token, an aged-out
     * token and a password change with the same `invalid_grant` and nothing else
     * to separate them. So the sentence names all three and says EzBizi cannot
     * tell which — and the assertions below check for exactly that rather than
     * for a confident guess at one of them.
     */
    public function test_a_broken_inbox_carries_a_sentence_naming_the_cause(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        ScriptedMailboxReader::$failWith = CannotReadMailbox::credentialRejected(
            $mailbox,
            // What Google actually puts on the wire, which must not reach the row.
            'invalid_grant: Token has been expired or revoked.',
        );

        $this->open($t, $mailbox)->assertStatus(409);

        $after = $this->reload($t, $mailbox);

        $this->assertSame(Mailbox::STATUS_BROKEN, $after->status);

        $reason = $after->status_reason;

        // The three causes the owner cares about, named — and the admission that
        // they cannot be told apart, which is the honest part.
        $this->assertStringContainsString('withdrew', $reason);
        $this->assertStringContainsString('expired', $reason);
        $this->assertStringContainsString('password was changed', $reason);
        $this->assertStringContainsString('connect this address again', $reason);

        // R8: what a person reads is never the machine's vocabulary.
        foreach ([
            'invalid_grant', 'CannotReadMailbox', 'MailboxFault', 'Exception',
            'App\\Support', '::', '409', 'null',
        ] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $reason,
                "The stored sentence carries '{$leak}', which is written for a programmer.",
            );
        }
    }

    /**
     * A rate limit is not a broken mailbox, and this is the case that would have
     * been got wrong.
     *
     * Gmail answers 403 both for a consent that no longer covers mail and for
     * asking too often. Treated as one, a busy afternoon marks a perfectly
     * healthy inbox broken and sends an administrator to reconnect it — real
     * work, done for nothing, ending in the same quota.
     */
    public function test_being_asked_to_slow_down_does_not_mark_the_inbox_broken(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        ScriptedMailboxReader::$failWith = CannotReadMailbox::tooManyRequests($mailbox);

        $this->open($t, $mailbox)->assertStatus(503);

        $after = $this->reload($t, $mailbox);

        $this->assertSame(Mailbox::STATUS_CONNECTED, $after->status, 'A quota marked the inbox broken.');
        $this->assertNull($after->status_reason);

        // THE CONTROL. The same route, the same mailbox, a cause that IS a
        // fault — so this test cannot pass by nothing ever marking anything.
        ScriptedMailboxReader::$failWith = CannotReadMailbox::permissionNarrowed($mailbox);

        $this->open($t, $mailbox)->assertStatus(409);

        $this->assertSame(Mailbox::STATUS_BROKEN, $this->reload($t, $mailbox)->status);
    }

    /**
     * A fault at EzBizi's end is never written onto a tenant's row.
     *
     * This installation has no Google registration, so if `notBuiltHere` marked
     * mailboxes broken, every inbox on it would go red the first time anybody
     * opened one — a wall of alarm describing a single fault the customer cannot
     * touch, with each row telling them to go and reconnect.
     */
    public function test_a_fault_at_our_end_does_not_blame_the_tenants_mailbox(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        // No script: the real Gmail reader, on an installation with no OAuth
        // client, which is the state this one is genuinely in.
        $this->open($t, $mailbox)->assertStatus(501);

        $after = $this->reload($t, $mailbox);

        $this->assertSame(Mailbox::STATUS_CONNECTED, $after->status);
        $this->assertNull($after->status_reason);
        $this->assertNull($after->last_reached_at);
    }

    /**
     * A mail server pointed somewhere we will not dial is a real, fixable fault —
     * and it is the one that would never reach a status screen unless the
     * recorder caught it, because it is not a read failure at all.
     *
     * ── Driven at the reader, not through a route ────────────────
     *
     * MailboxMessagesController::show catches CannotReadMailbox and not
     * CannotReachHost, so this exception escapes that route as a 500 today. That
     * is a real gap and it predates this work — the guard has always been able to
     * throw from inside ImapMailboxReader::assertUsable — but the controller is
     * not this change's to edit, and pinning the recorder's behaviour to a bug in
     * somebody else's file would make this test fail the day they fix it.
     *
     * So the wrapper is exercised where it lives. The assertions are the same
     * ones the route would make: the row goes broken, and the sentence sends
     * somebody to the mail server details rather than to the password.
     */
    public function test_a_refused_mail_server_is_recorded_against_the_inbox(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        ScriptedMailboxReader::$failWith = new CannotReachHost('That is not a host EzBizi will dial.');

        $this->inTenant($t['org'], function () use ($mailbox) {
            try {
                app(MailboxReaders::class)->for($mailbox)->list($mailbox);

                $this->fail('The refused host was swallowed instead of re-thrown.');
            } catch (CannotReachHost $e) {
                // Re-thrown, which matters: recording a failure is bookkeeping
                // BESIDE the failure and never instead of it.
                $this->assertStringContainsString('will dial', $e->getMessage());
            }
        });

        $after = $this->reload($t, $mailbox);

        $this->assertSame(Mailbox::STATUS_BROKEN, $after->status);
        $this->assertStringContainsString('mail server', $after->status_reason);
        $this->assertStringContainsString('correct the mail server details', $after->status_reason);

        // The credential is untouched by any of this — a bad host is not a bad
        // password, and nothing here should have cleared it.
        $this->assertTrue($after->hasCredential());
    }

    /** An inbox that answers again is not still broken. */
    public function test_a_mailbox_that_starts_working_again_heals_itself(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        ScriptedMailboxReader::$failWith = CannotReadMailbox::credentialRejected($mailbox);
        $this->open($t, $mailbox)->assertStatus(409);

        $this->assertSame(Mailbox::STATUS_BROKEN, $this->reload($t, $mailbox)->status);

        ScriptedMailboxReader::$failWith = null;
        $this->open($t, $mailbox)->assertOk();

        $healed = $this->reload($t, $mailbox);

        $this->assertSame(Mailbox::STATUS_CONNECTED, $healed->status);
        $this->assertNull($healed->status_reason, 'The screen still names a cause that has passed.');
        $this->assertNotNull($healed->last_reached_at);
    }

    // ══ 3. THE RESPONSE, AND WHAT MUST NEVER BE IN IT ════════════

    public function test_the_status_screen_gets_the_state_the_sentence_the_time_and_the_scope(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        ScriptedMailboxReader::$failWith = CannotReadMailbox::credentialRejected($mailbox);
        $this->open($t, $mailbox)->assertStatus(409);

        $row = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()->json('data.0');

        $this->assertSame(Mailbox::STATUS_BROKEN, $row['status']);
        $this->assertStringContainsString('withdrew', $row['status_reason']);
        $this->assertNull($row['last_reached_at'], 'It has never worked, and the row should say so.');

        // The provider, and which part of the business owns this address —
        // two companies in one organization can both run an info@.
        $this->assertSame('gmail_oauth', $row['provider']);
        $this->assertStringContainsString('Google', $row['provider_label']);
        $this->assertSame('organization', $row['scope_type']);
        $this->assertSame($t['org']->id, $row['scope_id']);

        // And once it has worked, the time is on the wire as an instant a client
        // can parse rather than as whatever the database handed back.
        ScriptedMailboxReader::$failWith = null;
        $this->open($t, $mailbox)->assertOk();

        $reached = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data.0.last_reached_at');

        $this->assertNotNull($reached);
        $this->assertNotFalse(strtotime($reached), "'{$reached}' is not a time anything can read.");
    }

    /**
     * The credential is masked twice over, and both locks survive this work.
     *
     * The model's MasksSensitiveValues covers every serialisation; the row
     * allowlist in the controller never names the column. This asserts the
     * OUTCOME of both against every new surface added here — the status screen,
     * the stored sentence, and the automation event a broken inbox raises.
     */
    public function test_nothing_added_here_lets_the_credential_out(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        ScriptedMailboxReader::$failWith = CannotReadMailbox::credentialRejected(
            $mailbox,
            // A provider that echoed the secret back in its error text, which is
            // the shape of accident this masking exists for.
            'the-refresh-token was rejected',
        );

        $failure = $this->open($t, $mailbox)->assertStatus(409);

        $listing = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        foreach ([$failure->getContent(), $listing->getContent()] as $body) {
            $this->assertStringNotContainsString('the-refresh-token', $body);
        }

        $after = $this->reload($t, $mailbox);

        $this->assertStringNotContainsString('the-refresh-token', (string) $after->status_reason);
        $this->assertStringNotContainsString('the-refresh-token', json_encode($after->toArray()));

        $event = AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'email.mailbox_broken')->firstOrFail();

        $this->assertStringNotContainsString('the-refresh-token', json_encode($event->payload));

        // THE CONTROL. The secret really is on the row — so the assertions above
        // are about masking rather than about a fixture that never set it.
        $this->assertSame('the-refresh-token', $after->credential());
    }

    // ══ 4. AUTOMATIONS ═══════════════════════════════════════════

    /**
     * A rule can hear about an inbox breaking — once per break.
     *
     * A mailbox that has been broken since Tuesday is refused every time anybody
     * opens it. Emitting on each of those would send *"tell the branch manager
     * when an inbox stops working"* one message per page load until somebody
     * fixed it, which is how a useful rule gets switched off in an afternoon.
     */
    public function test_a_broken_inbox_raises_one_automation_event_per_break(): void
    {
        $this->withScriptedProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        ScriptedMailboxReader::$failWith = CannotReadMailbox::credentialRejected($mailbox);

        $this->open($t, $mailbox)->assertStatus(409);
        $this->open($t, $mailbox)->assertStatus(409);
        $this->open($t, $mailbox)->assertStatus(409);

        $events = AutomationEvent::withoutGlobalScopes()->where('event_key', 'email.mailbox_broken')->get();

        $this->assertCount(1, $events, 'Every attempt raised an event, not every break.');

        $payload = $events->first()->payload;

        $this->assertSame('info@a-1chimney.com', $payload['address']);
        $this->assertSame(MailboxFault::SignInNoLongerValid->value, $payload['cause']);
        $this->assertStringContainsString('withdrew', $payload['reason']);

        // It works again, and then breaks again — which IS news, and is told.
        ScriptedMailboxReader::$failWith = null;
        $this->open($t, $mailbox)->assertOk();

        ScriptedMailboxReader::$failWith = CannotReadMailbox::credentialRejected($mailbox);
        $this->open($t, $mailbox)->assertStatus(409);

        $this->assertCount(
            2,
            AutomationEvent::withoutGlobalScopes()->where('event_key', 'email.mailbox_broken')->get(),
            'A second, separate break was silent.',
        );
    }

    // ══ 5. THE FUNNEL ════════════════════════════════════════════

    /**
     * Nothing gets a bare reader, and a test double cannot hand one out.
     *
     * The guarantee "no successful read goes unrecorded" is only worth having if
     * it is structural. `MailboxReaders::for` is `final` precisely so that a
     * subclass replacing the mail service cannot also replace the bookkeeping —
     * and the two providers below are both checked, because a `match` arm added
     * for a third provider is exactly the place this would be lost.
     */
    public function test_every_reader_handed_out_is_wrapped_in_the_recorder(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            foreach ([
                'gmail_oauth' => 'GmailMailboxReader',
                'gmail_workspace' => 'WorkspaceMailboxReader',
                'microsoft_oauth' => 'MicrosoftMailboxReader',
                'imap' => 'ImapMailboxReader',
            ] as $provider => $expected) {
                $mailbox = Mailbox::create([
                    'organization_id' => $t['org']->id,
                    'scope_type'      => 'organization',
                    'scope_id'        => $t['org']->id,
                    'address'         => "{$provider}@a-1chimney.com",
                    'display_name'    => 'Info',
                    'provider'        => $provider,
                ]);

                $reader = app(MailboxReaders::class)->for($mailbox);

                $this->assertInstanceOf(RecordingMailboxReader::class, $reader, $provider);
                $this->assertStringEndsWith($expected, $reader->inner::class, $provider);
            }
        });
    }

    // ══ 6. WHAT THE GMAIL READER CAN AND CANNOT TELL APART ═══════

    /**
     * The mapping, against the response shapes Google documents.
     *
     * No real Google account exists here, so what is asserted is the only part
     * that is code: given each documented shape, does the reader reach the cause
     * that puts the right person on the right job. The cases are chosen because
     * each pair below was previously reported as the SAME thing.
     *
     */
    #[DataProvider('googleFailures')]
    public function test_the_gmail_reader_separates_the_failures_it_can_see(
        array $token,
        int $tokenStatus,
        array $api,
        int $apiStatus,
        MailboxFault $expected,
    ): void {
        config([
            'services.google_mail.client_id'     => 'a-client-id',
            'services.google_mail.client_secret' => 'a-client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*'  => Http::response($token, $tokenStatus),
            'gmail.googleapis.com/*'   => Http::response($api, $apiStatus),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        try {
            $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox));

            $this->fail('The reader returned a page for a response that was a failure.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame($expected, $e->fault, 'Wrong cause for this response shape.');
        }
    }

    public static function googleFailures(): array
    {
        $ok = [['access_token' => 'an-access-token'], 200];

        return [
            // THE OWNER'S CASE. Withdrawn, expired and password-changed are one
            // reply, and the sentence behind this fault says so.
            'a sign-in Google will no longer accept' => [
                ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'],
                400, [], 200, MailboxFault::SignInNoLongerValid,
            ],

            // EZBIZI'S registration, not the tenant's mailbox — the distinction
            // that stops an installation telling every customer, individually,
            // that their password was changed.
            'EzBizi\'s own registration rejected' => [
                ['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.'],
                401, [], 200, MailboxFault::PlatformRejected,
            ],
            'EzBizi\'s registration rejected with a 400' => [
                ['error' => 'unauthorized_client'], 400, [], 200, MailboxFault::PlatformRejected,
            ],

            // Both of these are 403. One is a fault; the other is a busy minute.
            'consent narrowed away from reading mail' => [
                ...$ok,
                ['error' => ['message' => 'Request had insufficient authentication scopes.', 'errors' => [
                    ['reason' => 'insufficientPermissions'],
                ]]],
                403, MailboxFault::PermissionNarrowed,
            ],
            'asking too often' => [
                ...$ok,
                ['error' => ['message' => 'Rate Limit Exceeded', 'errors' => [
                    ['reason' => 'userRateLimitExceeded'],
                ]]],
                403, MailboxFault::TooManyRequests,
            ],
            'asking too often, said with a status' => [
                ...$ok, ['error' => ['message' => 'Too many requests']], 429, MailboxFault::TooManyRequests,
            ],

            // Google having a bad afternoon. Nothing about this mailbox.
            'the mail service is broken' => [
                ...$ok, ['error' => ['message' => 'Backend Error']], 503, MailboxFault::ProviderFaulty,
            ],

            // Refused, and not enough said to name a cause. Kept honest rather
            // than folded into "your sign-in was revoked", which would be a
            // guess presented as a diagnosis.
            'refused without saying why' => [
                ...$ok, ['error' => ['message' => 'Precondition check failed.']], 400,
                MailboxFault::ProviderRefused,
            ],
        ];
    }

    /** Nothing answered at all is its own case, and it has no status code to read. */
    public function test_a_mail_service_that_does_not_answer_is_told_from_one_that_refuses(): void
    {
        config([
            'services.google_mail.client_id'     => 'a-client-id',
            'services.google_mail.client_secret' => 'a-client-secret',
        ]);

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        try {
            $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox));

            $this->fail('A timeout returned a page.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame(MailboxFault::HostNotAnswering, $e->fault);

            // The timeout text is useful to whoever is debugging a connection
            // they just made, so the LIVE message may carry it...
            $this->assertStringContainsString('cURL error 28', $e->getMessage());
        }

        // ...and it must not be what a status screen shows. This is the split
        // the whole fault/sentence separation exists for.
        $this->assertStringNotContainsString(
            'cURL',
            MailboxFault::HostNotAnswering->sentence('info@a-1chimney.com'),
        );
    }

    /**
     * Every cause has a sentence somebody can act on.
     *
     * A loop over an enum passes vacuously if the enum empties, so the count is
     * asserted too. Each sentence has to name the address (so a screen listing
     * four inboxes says which one) and say something about what happens next.
     */
    public function test_every_cause_has_a_sentence_written_for_a_person(): void
    {
        $checked = 0;

        foreach (MailboxFault::cases() as $fault) {
            $sentence = $fault->sentence('info@a-1chimney.com');

            $this->assertStringContainsString('info@a-1chimney.com', $sentence, $fault->value);
            $this->assertStringEndsWith('.', trim($sentence), $fault->value);
            $this->assertGreaterThan(80, strlen($sentence), $fault->value);

            foreach (['App\\', '::', 'Exception', $fault->value] as $leak) {
                $this->assertStringNotContainsString($leak, $sentence, "{$fault->value} leaks '{$leak}'");
            }

            $checked++;
        }

        $this->assertGreaterThanOrEqual(10, $checked, 'The causes have gone missing.');
    }
}

/**
 * A mail service this test decides the behaviour of.
 *
 * Static rather than injected, because the object under test is obtained from
 * the container deep inside a controller and there is no seam to hand an
 * instance through.
 */
class ScriptedMailboxReader implements MailboxReader
{
    public static ?Throwable $failWith = null;

    public static function reset(): void
    {
        self::$failWith = null;
    }

    private function answerOrFail(): void
    {
        if (self::$failWith !== null) {
            throw self::$failWith;
        }
    }

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25): MessagePage
    {
        $this->answerOrFail();

        return new MessagePage([$this->summary()]);
    }

    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25): MessagePage
    {
        $this->answerOrFail();

        return new MessagePage([$this->summary()]);
    }

    public function open(Mailbox $mailbox, string $messageId): MessageDetail
    {
        $this->answerOrFail();

        return new MessageDetail($this->summary(), [Block::paragraph([])]);
    }

    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $this->answerOrFail();
    }

    /**
     * The two watch calls, and they fail exactly like the other four.
     *
     * That is the whole point of them being here: this file's subject is that
     * every completed round trip stamps `last_reached_at` and every failed one
     * writes the sentence, and the background watcher must be no exception. A
     * fake that answered these unconditionally would let a poll bypass the
     * health path without a single test noticing.
     */
    public function currentMark(Mailbox $mailbox): string
    {
        $this->answerOrFail();

        return 'mark-1';
    }

    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals
    {
        $this->answerOrFail();

        return new Arrivals([$this->summary()], 'mark-2');
    }

    public function isConfigured(Mailbox $mailbox): bool
    {
        return true;
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        return null;
    }

    /** A scripted reader stands in for a provider that IS ready. */
    public function notReadyBecause(): ?string
    {
        return null;
    }

    private function summary(): MessageSummary
    {
        return new MessageSummary(
            id: 'm-1',
            threadId: 't-1',
            subject: 'Quote for a sweep',
            fromName: 'Bob Smith',
            fromAddress: 'bob@example.com',
            to: ['info@a-1chimney.com'],
            sentAt: Carbon::parse('2026-08-11 09:00:00'),
            snippet: 'Could you quote for two flues',
        );
    }
}

/**
 * Substitutes the MAIL SERVICE and nothing else.
 *
 * `readerFor` and not `for`: the latter is final, and it is what wraps the
 * reader in RecordingMailboxReader. A double that overrode it would quietly
 * remove the very bookkeeping these tests are about.
 */
class ScriptedMailboxReaders extends MailboxReaders
{
    protected function readerFor(Mailbox $mailbox): MailboxReader
    {
        return new ScriptedMailboxReader;
    }
}
