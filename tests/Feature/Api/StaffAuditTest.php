<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * What the control plane records (plan §4.2, §10).
 *
 * The Lead Developer's exemption is deliberate. These are the carve-outs it must
 * not swallow, and the plumbing that has to work for any of it to be traceable.
 */
class StaffAuditTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(Google2FA::class);
    }

    private function signedIn(string $tier = PlatformStaff::TIER_LEAD): array
    {
        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => $tier.'@ezbizi.test',
            'password' => 'a-sufficiently-long-password', 'tier' => $tier, 'is_active' => true,
        ]);

        $secret = $this->totp->generateSecretKey(32);
        $staff->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        $token = $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');

        return [$staff, "Bearer {$token}"];
    }

    // ── the plumbing ─────────────────────────────────────────────

    public function test_the_correlation_id_returned_to_the_client_is_the_one_on_the_row(): void
    {
        [$staff, $auth] = $this->signedIn();

        $response = $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/auth/logout')->assertOk();

        // AuditLogger holds per-request state and must be a shared instance. Left
        // unbound, the middleware stamps one id on the header while every writer
        // mints its own — and "it broke at 3pm" stays unmatchable against the log,
        // which is the one thing a correlation id exists to fix.
        $this->assertSame(
            $response->headers->get('X-Correlation-Id'),
            AuditEvent::where('action', 'staff.signed_out')->value('correlation_id'),
        );
    }

    public function test_a_malformed_correlation_id_header_is_replaced_rather_than_stored(): void
    {
        [$staff, $auth] = $this->signedIn();

        // It lands in a CHAR(36) column. Anything longer would be a 500 raised
        // after the side effect had already committed.
        $this->withHeader('Authorization', $auth)
            ->withHeader('X-Correlation-Id', str_repeat('a', 200))
            ->postJson('/api/v1/staff/auth/logout')
            ->assertOk();

        $stored = AuditEvent::where('action', 'staff.signed_out')->value('correlation_id');

        $this->assertNotSame(str_repeat('a', 200), $stored);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($stored));
    }

    public function test_lead_developer_suppression_reaches_beyond_the_middleware(): void
    {
        [$staff, $auth] = $this->signedIn();

        // Resolved the way a controller would resolve it — a different object
        // entirely unless the binding is shared.
        $this->withHeader('Authorization', $auth)->getJson('/api/v1/staff/organizations');

        $logger = app(AuditLogger::class);

        $written = $logger->suppressForLeadDeveloper(
            fn () => app(AuditLogger::class)->log('something.ordinary')
        );

        $this->assertNull($written, 'Suppression did not reach a separately resolved logger.');
    }

    // ── the carve-outs ───────────────────────────────────────────

    public function test_a_failed_sign_in_is_recorded(): void
    {
        PlatformStaff::create([
            'name' => 'Jose', 'email' => 'lead@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'lead@ezbizi.test', 'password' => 'wrong', 'code' => '000000',
        ])->assertUnauthorized();

        // Successes alone are not a security record — someone grinding the
        // control plane would otherwise leave nothing but a rate-limiter counter
        // that expires in fifteen minutes.
        $event = AuditEvent::where('action', 'staff.sign_in_failed')->first();

        $this->assertNotNull($event);
        $this->assertSame('lead@ezbizi.test', $event->metadata['email']);
        $this->assertSame('password', $event->metadata['stage']);
    }

    public function test_a_module_state_change_is_recorded_even_for_the_lead_developer(): void
    {
        // 'jobs' is a real registered module now that the vertical slice exists
        // (§18 Phase 2) — this used to register a stand-in for it.
        $this->assertNotNull(app(ModuleRegistry::class)->get('jobs'));

        [$staff, $auth] = $this->signedIn();

        $this->withHeader('Authorization', $auth)
            ->patchJson('/api/v1/staff/modules/jobs', [
                'state'  => 'disabled',
                'reason' => 'Broken export, disabled while it is fixed.',
            ])->assertOk();

        // The only staff action that changes what every customer sees at once,
        // and `changed_by` on the flag row is overwritten by the next change.
        $event = AuditEvent::where('action', 'staff.module_state_changed')->first();

        $this->assertNotNull($event);
        $this->assertSame('jobs', $event->metadata['module']);
        $this->assertSame('enabled', $event->metadata['before']);
        $this->assertSame('disabled', $event->metadata['after']);
    }

    public function test_an_impersonation_row_carries_the_organization_it_concerns(): void
    {
        [$staff, $auth] = $this->signedIn();

        ['organization' => $org] = app(\App\Services\Organizations\ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'admin@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/impersonations', [
                'organization_id' => $org->id,
                'reason'          => 'Investigating a reported export failure.',
            ])->assertCreated();

        // Staff run in platform scope, so nothing infers this — and "everything
        // that happened to this client" is the query the row exists to answer.
        $this->assertSame(
            $org->id,
            AuditEvent::where('action', 'staff.impersonation_started')->value('organization_id'),
        );
    }

    public function test_recovery_codes_would_be_redacted_if_they_ever_reached_metadata(): void
    {
        $redacted = app(AuditLogger::class)->redact([
            'recovery_codes'      => ['ABCDE-FGHIJ'],
            'recovery_code_count' => 8,
        ]);

        // A recovery code is a full second-factor bypass. The count is just a
        // number and has to stay readable, which is why the needle is plural.
        $this->assertSame('[redacted]', $redacted['recovery_codes']);
        $this->assertSame(8, $redacted['recovery_code_count']);
    }
}
