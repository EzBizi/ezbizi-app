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

    public function test_staff_login_is_not_served_on_the_customer_host(): void
    {
        [$staff, $secret] = $this->staff();

        // An absolute URL so Symfony takes the host from it — a relative
        // path is built against APP_URL (localhost) and HTTP_HOST is ignored.
        $this->postJson('http://app.ezbizi.com/api/v1/staff/auth/login', [
            'email'    => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code'     => $this->totp->getCurrentOtp($secret),
        ])->assertNotFound();
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

        $denied = $this->postJson('/api/v1/staff/auth/login', [
            'email'    => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code'     => $this->totp->getCurrentOtp($secret),
        ])->assertForbidden()->assertJsonPath('code', 'totp_enrolment_required');

        $message = $denied->json('message');
        $this->assertStringNotContainsString('artisan', $message);
        $this->assertStringNotContainsString('php ', $message);
        $this->assertStringNotContainsString($staff->email, $message);
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

    public function test_a_developer_cannot_self_attest_client_approval(): void
    {
        [$staff, $secret] = $this->staff(PlatformStaff::TIER_DEV);
        ['organization' => $org] = $this->organization();

        $token = $this->signIn($staff, $secret);

        // The previous grant was a boolean on this body. Sending it must not
        // be enough, whether or not the field is still accepted.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/impersonations', [
                'organization_id'  => $org->id,
                'reason'           => 'Investigating a reported export failure.',
                'client_approved'  => true,
                'approved_by_user_id' => $org->id,
            ])->assertStatus(422);

        $this->assertDatabaseCount('staff_impersonations', 0);
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

    /**
     * Somebody else's session, and the refusal says whose and what happens next.
     *
     * The list is deliberately everybody's — a log only its author can read
     * supervises nobody — so the person reading this 403 is a colleague looking
     * at a row that is genuinely on their screen. It used to be a bare
     * `abort_unless(..., 403)` answering "This action is unauthorized.", which
     * reads as a broken button rather than as a rule (R8).
     */
    public function test_closing_somebody_elses_impersonation_says_who_can(): void
    {
        [$opener, ]            = $this->staff(PlatformStaff::TIER_LEAD);
        [$other, $otherSecret] = $this->staff(PlatformStaff::TIER_DEV);

        ['organization' => $org] = $this->organization();

        $session = app(ImpersonationService::class)
            ->begin($opener, $org, 'Checking a reported bug.', minutes: 30);

        $message = $this->withHeader('Authorization', 'Bearer '.$this->signIn($other, $otherSecret))
            ->deleteJson("/api/v1/staff/impersonations/{$session->getKey()}")
            ->assertForbidden()
            ->json('message');

        $this->assertStringNotContainsString('This action is unauthorized', $message);
        $this->assertStringContainsString('who opened an impersonation', $message);

        // The remedy half: two of them, because "ask them" fails when they have
        // gone home and the session still has to end.
        $this->assertStringContainsString('Ask them', $message);
        $this->assertStringContainsString('expires on its own', $message);

        // And it really is still open — the refusal changed nothing.
        $this->assertNull($session->fresh()->ended_at);
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

    /** The search bar on the impersonation log: `q` narrows by client or reason; a blank `q` is no filter. */
    public function test_impersonations_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        [$staff, $secret] = $this->staff();
        ['organization' => $alpha] = $this->organization('Alpha');
        ['organization' => $beta] = $this->organization('Beta');
        $token = $this->signIn($staff, $secret);
        $as = $this->withHeader('Authorization', "Bearer {$token}");

        $as->postJson('/api/v1/staff/impersonations', ['organization_id' => $alpha->id, 'reason' => 'Investigating a reported export failure.'])->assertCreated();
        $as->postJson('/api/v1/staff/impersonations', ['organization_id' => $beta->id, 'reason' => 'Checking their invoice numbering.'])->assertCreated();

        $as->getJson('/api/v1/staff/impersonations?q=invoice')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.organization.name', 'Beta');
        $as->getJson('/api/v1/staff/impersonations?q=alpha')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.organization.name', 'Alpha');
        $as->getJson('/api/v1/staff/impersonations?q=')->assertOk()->assertJsonCount(2, 'data');
        $as->getJson('/api/v1/staff/impersonations')->assertOk()->assertJsonCount(2, 'data');
    }
}
