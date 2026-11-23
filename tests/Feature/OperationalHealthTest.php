<?php

namespace Tests\Feature;

use App\Models\PlatformStaff;
use App\Notifications\PasswordResetLink;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Mail\MailStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The subsystems that fail silently (plan §18 Phase 0, §4.2).
 *
 * Every one of these has the same failure shape: it stops, and nothing about the
 * app looks different. That is why they are tested for what they REPORT, not
 * just for what they do — a check that says "healthy" when it cannot tell is the
 * actual defect.
 */
class OperationalHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $statusFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statusFile = storage_path('app/backup-status-test.json');
        config(['ezbizi.backups.status_file' => $this->statusFile]);

        // Pointed at a closed port for the whole class.
        //
        // The certificate check reads what is being served over the wire, which
        // means that left at its default the suite would open a TLS connection
        // to the real production host — a test whose result depends on the
        // network it runs on, and which stalls for the full timeout wherever
        // there isn't one. Port 1 refuses immediately and deterministically.
        // Tests that care set their own value.
        config(['ezbizi.tls.host' => '127.0.0.1', 'ezbizi.tls.port' => 1]);

        @unlink($this->statusFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->statusFile);
        parent::tearDown();
    }

    private function writeStatus(array $status): void
    {
        file_put_contents($this->statusFile, json_encode($status));
    }

    // ── mail ─────────────────────────────────────────────────────

    public function test_the_log_transport_is_reported_as_not_delivering(): void
    {
        config(['mail.default' => 'log']);

        // The whole reason this class exists: `log` accepts every message and
        // returns success. From the endpoint that "sent" it, a working transport
        // and this are identical.
        $this->assertFalse(app(MailStatus::class)->delivers());
    }

    public function test_smtp_without_credentials_names_what_is_missing(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'noreply@ezbizi.com',
            'mail.mailers.smtp.username' => null,
        ]);

        $this->assertContains('MAIL_USERNAME', app(MailStatus::class)->missing());
    }

    public function test_the_placeholder_from_address_counts_as_unconfigured(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'hello@example.com',
            'mail.mailers.smtp.username' => 'a-user',
        ]);

        // Worse than blank: SES rejects a From it has no identity for, so every
        // send fails citing a domain nobody meant to use.
        $this->assertContains('MAIL_FROM_ADDRESS', app(MailStatus::class)->missing());
        $this->assertFalse(app(MailStatus::class)->delivers());
    }

    public function test_a_fully_configured_transport_reports_healthy(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'noreply@ezbizi.com',
            'mail.mailers.smtp.username' => 'a-user',
        ]);

        $this->assertTrue(app(MailStatus::class)->delivers());
    }

    public function test_reset_links_come_from_config_not_from_the_source(): void
    {
        Notification::fake();

        config(['ezbizi.hosts.customer' => 'https://staging.example.test']);

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->postJson('/api/v1/auth/forgot', ['email' => 'a@alpha.test'])->assertOk();

        // Hardcoded hosts meant any second installation would have emailed
        // production links to its own users.
        Notification::assertSentOnDemand(
            PasswordResetLink::class,
            function (PasswordResetLink $notification) use ($r) {
                $rendered = $notification->toMail($r['administrator'])->actionUrl;

                return str_starts_with($rendered, 'https://staging.example.test/reset?');
            },
        );
    }

    // ── backups ──────────────────────────────────────────────────

    public function test_a_missing_verdict_is_a_failure_not_an_unknown(): void
    {
        // A timer that has stopped writes nothing at all, so treating "no file"
        // as "no news" is exactly how a dead backup stays unnoticed.
        $this->artisan('ezbizi:check-backups')->assertFailed();
    }

    public function test_a_stale_verdict_is_a_failure_however_good_it_was(): void
    {
        $this->writeStatus([
            'ok' => true, 'stage' => 'restore-check',
            'at' => now()->subDays(3)->toIso8601String(), 'errors' => [],
        ]);

        // Yesterday's success sits in the file forever. Judging freshness here,
        // rather than trusting `ok`, is what makes a stopped timer visible.
        $this->artisan('ezbizi:check-backups')->assertFailed();
    }

    public function test_a_backup_that_was_never_restore_checked_is_a_failure(): void
    {
        $this->writeStatus([
            'ok' => true, 'stage' => 'backup',
            'at' => now()->subHour()->toIso8601String(), 'errors' => [],
        ]);

        // §18 Phase 0 item 5 says "backups WITH A VERIFIED RESTORE". An
        // unverified backup is indistinguishable from a working one until the
        // day it matters, which makes it worse than none.
        $this->artisan('ezbizi:check-backups')->assertFailed();
    }

    public function test_a_reported_failure_is_a_failure(): void
    {
        $this->writeStatus([
            'ok' => false, 'stage' => 'backup',
            'at' => now()->subHour()->toIso8601String(),
            'errors' => ['offsite-copy'],
        ]);

        $this->artisan('ezbizi:check-backups')->assertFailed();
    }

    public function test_a_fresh_verified_restore_passes(): void
    {
        $this->writeStatus([
            'ok' => true, 'stage' => 'restore-check', 'from' => now()->toDateString(),
            'at' => now()->subHour()->toIso8601String(), 'errors' => [],
        ]);

        $this->artisan('ezbizi:check-backups')->assertSuccessful();
    }

    public function test_an_unhealthy_backup_is_recorded_even_though_lead_developers_are_not_logged(): void
    {
        $this->artisan('ezbizi:check-backups')->assertFailed();

        // §4.2: the Lead Developer is not logged EXCEPT for errors. This is the
        // category that exception exists for — the audit trail is where somebody
        // finds out how long it had been broken.
        $this->assertDatabaseHas('audit_events', ['action' => 'backup.unhealthy']);
    }

    // ── the certificate ──────────────────────────────────────────

    public function test_a_certificate_that_cannot_be_read_is_a_failure_not_an_unknown(): void
    {
        $health = $this->certificateReading();

        // Same rule as the backup verdict, for the same reason: on a screen
        // somebody glances at, "I could not tell" and "it is fine" are the same
        // green tick, and only one of them is true.
        $this->assertFalse($health['ok']);

        // Null rather than 0. Zero is a measurement — it says the certificate
        // expires today — and there is no measurement here.
        $this->assertNull($health['days_remaining']);
        $this->assertNull($health['expires_at']);
        $this->assertNotNull($health['problem']);
    }

    public function test_the_certificate_check_names_the_host_it_could_not_read(): void
    {
        config(['ezbizi.tls.host' => '127.0.0.1', 'ezbizi.tls.port' => 1]);

        // The point of the whole screen is that a red icon beside an empty
        // sentence is useless. Whoever reads this has to know which host and
        // which port produced nothing.
        $this->assertStringContainsString('127.0.0.1:1', $this->certificateReading()['problem']);
    }

    /**
     * The certificate block, read the way the staff screen reads it.
     *
     * Goes through the endpoint rather than the controller so it exercises what
     * actually ships — including the fact that a check which cannot reach
     * anything must not take the whole health screen down with it.
     */
    private function certificateReading(): array
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->staffToken())
            ->getJson('/api/v1/staff/health')
            ->assertOk();

        return $response->json('certificate');
    }

    /**
     * A signed-in Lead Developer, which is the only thing that can read /health.
     *
     * The enrol-then-login dance is real rather than faked because the staff
     * guard is a second factor, not a flag: a token minted around it would let
     * these tests pass against a route that nobody could actually reach.
     */
    private function staffToken(): string
    {
        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => 'staff@ezbizi.test',
            'password' => 'a-real-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $staff->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        $token = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'staff@ezbizi.test', 'password' => 'a-real-staff-password',
            'code' => $totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    // ── the staff-facing view ────────────────────────────────────

    public function test_health_is_staff_only(): void
    {
        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // The answers name hosts, transports and file paths — a customer's
        // administrator has no business reading them.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/health')->assertForbidden();
    }

    public function test_staff_see_every_subsystem_at_once(): void
    {
        $token = $this->staffToken();

        config(['mail.default' => 'log']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/health')
            ->assertOk()
            ->assertJsonPath('backups.ok', false)
            ->assertJsonPath('mail.delivers', false)
            ->assertJsonPath('outbox.ok', true)
            ->assertJsonPath('certificate.ok', false)
            ->assertJsonPath('peer.ok', false);
    }

    public function test_a_delayed_event_is_not_an_overdue_outbox(): void
    {
        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );
        $org = $r['organization'] ?? $r->organization ?? \App\Models\Organization::query()->first();
        $event = fn ($occurred, $available) => app(\App\Support\Tenancy\TenantContext::class)->run($org, fn () => \App\Models\AutomationEvent::create([
            'organization_id' => $org->id, 'event_key' => 'lead.call_not_booked',
            'payload' => [], 'occurred_at' => $occurred, 'available_at' => $available,
        ]));
        $token = $this->staffToken();
        $health = fn () => $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/staff/health');

        // Waiting two hours on purpose: not a stalled scheduler (11-11).
        $event(now()->subHours(3), now()->addMinutes(30));
        $health()->assertJsonPath('outbox.ok', true);

        $event(now()->subHour(), now()->subMinutes(30));
        $health()->assertJsonPath('outbox.ok', false)->assertJsonPath('outbox.oldest_minutes', 30);
    }

    public function test_the_peer_url_does_not_default_to_this_box(): void
    {
        $url = (string) config('ezbizi.peer.url');

        $this->assertStringNotContainsString(
            'www.ezbizi.com',
            $url,
            'www.ezbizi.com is the app box. Defaulting the peer URL there makes '
            .'the watchdog ask itself whether it is alive.',
        );
        $this->assertStringNotContainsString('ezbizi-alive.txt', $url);
    }

    public function test_an_unset_peer_url_names_the_env_var(): void
    {
        $token = $this->staffToken();
        config(['ezbizi.peer.url' => '']);

        $problem = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/health')
            ->assertOk()
            ->assertJsonPath('peer.ok', false)
            ->json('peer.problem');

        $this->assertStringContainsString('EZBIZI_PEER_URL', $problem);
    }

    public function test_a_missing_peer_verdict_is_a_failure(): void
    {
        $token = $this->staffToken();
        config(['ezbizi.peer.url' => 'https://panel.triplexhost.net']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/health')
            ->assertOk()
            ->assertJsonPath('peer.ok', false);

        $this->assertNotEmpty(
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/v1/staff/health')->json('peer.problem')
        );
    }

    public function test_a_fresh_peer_ok_is_healthy(): void
    {
        $token = $this->staffToken();
        $path = storage_path('app/peer-status-test.json');
        config([
            'ezbizi.peer.status_file' => $path,
            'ezbizi.peer.url' => 'https://panel.triplexhost.net',
        ]);

        file_put_contents($path, json_encode([
            'ok' => true,
            'at' => now()->toIso8601String(),
            'host' => 'panel.triplexhost.net',
        ]));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/health')
            ->assertOk()
            ->assertJsonPath('peer.ok', true);

        @unlink($path);
    }

    public function test_the_framework_up_probe_answers_when_mysql_does(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_a_test_email_refuses_rather_than_pretending(): void
    {
        $token = $this->staffToken();

        config(['mail.default' => 'log']);

        // "Sent" against a transport that goes nowhere is the answer that made
        // this whole screen necessary.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/health/test-email', ['address' => 'someone@example.test'])
            ->assertOk()
            ->assertJsonPath('sent', false);
    }

    public function test_sending_a_test_email_is_recorded(): void
    {
        $token = $this->staffToken();

        // The same shape as a healthy transport above, so the send is attempted
        // rather than refused at the door — faked, so nothing leaves the box.
        config([
            'mail.default'               => 'smtp',
            'mail.from.address'          => 'noreply@ezbizi.com',
            'mail.mailers.smtp.username' => 'a-user',
        ]);

        \Illuminate\Support\Facades\Mail::fake();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/health/test-email', ['address' => 'someone@example.test'])
            ->assertOk()
            ->assertJsonPath('sent', true);

        // The one control on this screen that DOES something rather than
        // reporting something: real mail, from the platform's identity, to any
        // address the caller types. Recorded through logStaff, so it is written
        // even for the account the audit exemption covers.
        $event = \App\Models\AuditEvent::where('action', 'staff.test_email_sent')->first();

        $this->assertNotNull($event);
        $this->assertSame(\App\Models\AuditEvent::ACTOR_STAFF, $event->actor_type);
        $this->assertSame('someone@example.test', $event->metadata['address']);
    }
}
