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
 * The three subsystems that fail silently (plan §18 Phase 0, §4.2).
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

    public function test_ses_without_credentials_names_what_is_missing(): void
    {
        config([
            'mail.default' => 'ses',
            'mail.from.address' => 'noreply@ezbizi.com',
            'services.ses.key' => null,
            'services.ses.secret' => null,
        ]);

        $missing = app(MailStatus::class)->missing();

        $this->assertContains('AWS_ACCESS_KEY_ID', $missing);
        $this->assertContains('AWS_SECRET_ACCESS_KEY', $missing);
    }

    public function test_the_placeholder_from_address_counts_as_unconfigured(): void
    {
        config([
            'mail.default' => 'ses',
            'mail.from.address' => 'hello@example.com',
            'services.ses.key' => 'a-key',
            'services.ses.secret' => 'a-secret',
        ]);

        // Worse than blank: SES rejects a From it has no identity for, so every
        // send fails citing a domain nobody meant to use.
        $this->assertContains('MAIL_FROM_ADDRESS', app(MailStatus::class)->missing());
        $this->assertFalse(app(MailStatus::class)->delivers());
    }

    public function test_a_fully_configured_transport_reports_healthy(): void
    {
        config([
            'mail.default' => 'ses',
            'mail.from.address' => 'noreply@ezbizi.com',
            'services.ses.key' => 'a-key',
            'services.ses.secret' => 'a-secret',
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

        config(['mail.default' => 'log']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/health')
            ->assertOk()
            ->assertJsonPath('backups.ok', false)
            ->assertJsonPath('mail.delivers', false)
            ->assertJsonPath('outbox.ok', true);
    }

    public function test_a_test_email_refuses_rather_than_pretending(): void
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

        config(['mail.default' => 'log']);

        // "Sent" against a transport that goes nowhere is the answer that made
        // this whole screen necessary.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/health/test-email', ['address' => 'someone@example.test'])
            ->assertOk()
            ->assertJsonPath('sent', false);
    }
}
