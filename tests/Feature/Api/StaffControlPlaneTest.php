<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformStaff;
use App\Models\StaffImpersonation;
use App\Services\Organizations\ProvisionOrganization;
use App\Services\Staff\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The platform control plane (plan §4.2).
 *
 * The boundaries under test are the ones that would be catastrophic to get
 * wrong: a tenant token reaching staff endpoints, a staff token reaching tenant
 * data, and an impersonation that isn't recorded.
 */
class StaffControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(Google2FA::class);
    }

    /** @return array{0: PlatformStaff, 1: string} the account and its TOTP secret */
    private function staff(string $tier = PlatformStaff::TIER_LEAD, bool $enrolled = true): array
    {
        $staff = PlatformStaff::create([
            'name'      => 'Jose',
            'email'     => $tier.'@ezbizi.test',
            'password'  => 'a-sufficiently-long-password',
            'tier'      => $tier,
            'is_active' => true,
        ]);

        $secret = $this->totp->generateSecretKey(32);
        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = $enrolled ? now() : null;
        $staff->save();

        return [$staff, $secret];
    }

    private function signIn(PlatformStaff $staff, string $secret): string
    {
        return $this->postJson('/api/v1/staff/auth/login', [
            'email'    => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code'     => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');
    }

    private function organization(string $name = 'Alpha'): array
    {
        return app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => 'admin@'.strtolower($name).'.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );
    }

    // ── authentication ───────────────────────────────────────────

    public function test_a_correct_password_alone_does_not_sign_in(): void
    {
        [$staff, ] = $this->staff();

        $this->postJson('/api/v1/staff/auth/login', [
            'email'    => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code'     => '000000',
        ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_password_and_code_together_issue_a_working_token(): void
    {
        [$staff, $secret] = $this->staff();

        $token = $this->signIn($staff, $secret);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/me')
            ->assertOk()
            ->assertJsonPath('data.tier', PlatformStaff::TIER_LEAD);
    }

    public function test_an_account_without_confirmed_totp_cannot_sign_in(): void
    {
        // TOTP is mandatory for staff — an unenrolled account is inert, not
        // password-only.
        [$staff, $secret] = $this->staff(enrolled: false);

        $this->postJson('/api/v1/staff/auth/login', [
            'email'    => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code'     => $this->totp->getCurrentOtp($secret),
        ])->assertForbidden()->assertJsonPath('code', 'totp_enrolment_required');
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$staff, $secret] = $this->staff();

        $auth = app(\App\Services\Staff\StaffAuthenticator::class);

        // Recovery codes are issued when a pending secret is confirmed, so this
        // goes through the real enrolment path rather than poking the model.
        $pending = $auth->beginEnrolment($staff, revokeExisting: false);
        $codes = $auth->completeEnrolment($staff, $this->totp->getCurrentOtp($pending['secret']));

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password', 'code' => $codes[0],
        ])->assertOk();

        // Second use must fail, or a leaked list is permanent access.
        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password', 'code' => $codes[0],
        ])->assertUnauthorized();
    }

    // ── the two boundaries that matter most ──────────────────────

    public function test_a_tenant_token_cannot_reach_staff_endpoints(): void
    {
        $this->organization();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@alpha.test', 'password' => 'a-real-password',
        ])->json('token');

        // Sanctum validates the token without checking which model issued it, so
        // this passes auth:sanctum and must be stopped by the staff middleware.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/organizations')
            ->assertForbidden();
    }

    public function test_a_staff_token_cannot_reach_tenant_endpoints(): void
    {
        [$staff, $secret] = $this->staff();
        $this->organization();

        $token = $this->signIn($staff, $secret);

        // Staff have no organization. Reaching customer records is what an
        // impersonation session is for.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertForbidden();
    }

    // ── impersonation ────────────────────────────────────────────

    public function test_a_developer_cannot_enter_a_tenant_without_client_approval(): void
    {
        [$staff, $secret] = $this->staff(PlatformStaff::TIER_DEV);
        ['organization' => $org] = $this->organization();

        $token = $this->signIn($staff, $secret);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/impersonations', [
                'organization_id' => $org->id,
                'reason'          => 'Investigating a reported export failure.',
            ])->assertStatus(422);
    }

    public function test_the_lead_developer_may_enter_without_approval_and_it_is_still_recorded(): void
    {
        [$staff, $secret] = $this->staff();
        ['organization' => $org] = $this->organization();

        $token = $this->signIn($staff, $secret);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/impersonations', [
                'organization_id' => $org->id,
                'reason'          => 'Investigating a reported export failure.',
            ])->assertCreated()->assertJsonPath('data.client_approved', false);

        // The Lead Developer's ordinary audit is suppressed. This must not be —
        // it is the record that answers a client asking who opened their data.
        $this->assertDatabaseHas('audit_events', [
            'action'   => 'staff.impersonation_started',
            'actor_id' => $staff->id,
        ]);
    }

    public function test_an_impersonation_requires_a_stated_reason(): void
    {
        [$staff, $secret] = $this->staff();
        ['organization' => $org] = $this->organization();

        $token = $this->signIn($staff, $secret);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/impersonations', [
                'organization_id' => $org->id,
                'reason'          => '   ',
            ])->assertStatus(422);
    }

    public function test_an_expired_session_stops_granting_access(): void
    {
        [$staff, ] = $this->staff();
        ['organization' => $org] = $this->organization();

        $service = app(ImpersonationService::class);
        $session = $service->begin($staff, $org, 'Checking a reported bug.', minutes: 30);

        $this->assertTrue($session->isActive());

        // Time-limited has to mean the access stops by itself, not that someone
        // remembered to close the tab.
        $this->travel(31)->minutes();

        $this->assertFalse($session->isActive());

        $this->expectException(InvalidArgumentException::class);
        $service->enter($session, fn () => 'should not run');
    }

    public function test_expiring_stale_sessions_closes_and_records_them(): void
    {
        [$staff, ] = $this->staff();
        ['organization' => $org] = $this->organization();

        $service = app(ImpersonationService::class);
        $service->begin($staff, $org, 'Checking a reported bug.', minutes: 5);

        $this->travel(10)->minutes();

        $this->assertSame(1, $service->expireStale());

        // An open-ended row is ambiguous between "still running" and "nobody
        // closed it" — different answers to give a client.
        $this->assertNotNull(StaffImpersonation::first()->ended_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.impersonation_expired']);
    }

    public function test_ordinary_lead_developer_activity_is_not_audited(): void
    {
        [$staff, $secret] = $this->staff();
        $this->organization();

        $token = $this->signIn($staff, $secret);

        // Straight to the query builder: the model refuses deletes, and rightly.
        \Illuminate\Support\Facades\DB::table('audit_events')->delete();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/organizations')->assertOk();

        // The owner's decision (§4.2): development activity does not flood the
        // business trail. Sign-ins and impersonations are the carve-outs.
        $this->assertSame(0, AuditEvent::count());
    }
}
