<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * One human, two identities, linked (plan §4.2, revised 2026-08-06).
 *
 * The owner asked to use one account for the CRM and the control plane. The
 * records stay separate — one has no organization and one belongs to exactly
 * one — but authentication is unified: a linked user proves themselves with the
 * STAFF credential set, whichever door they use.
 *
 * What these defend: that unifying the login did not also unify the powers.
 */
class LinkedAccountTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    private array $org;

    private PlatformStaff $staff;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = app(Google2FA::class);

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Jose', 'email' => 'jose@ezbizi.test', 'password' => 'tenant-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = ['organization' => $r['organization'], 'admin' => $r['administrator']];

        $this->staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => 'jose@ezbizi.test',
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $this->secret = $this->totp->generateSecretKey(32);
        $this->staff->forceFill([
            'totp_secret' => $this->secret, 'totp_confirmed_at' => now(),
        ])->save();
    }

    private function link(): void
    {
        PlatformStaffLink::create([
            'staff_id' => $this->staff->getKey(),
            'user_id' => $this->org['admin']->getKey(),
        ]);
    }

    /** A fresh code — each one works once. */
    private function code(): string
    {
        PlatformStaff::whereKey($this->staff->getKey())->update(['last_totp_timestamp' => null]);

        return $this->totp->getCurrentOtp($this->secret);
    }

    // ── the unified login ────────────────────────────────────────

    public function test_an_unlinked_user_signs_in_exactly_as_before(): void
    {
        // Ordinary customers must be untouched by any of this — no code field,
        // no second step, nothing.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'tenant-password',
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_a_linked_user_authenticates_against_the_staff_password(): void
    {
        $this->link();

        // The old tenant password stops working: the staff record is the single
        // authority for anyone linked to it, rather than two passwords kept in
        // sync — which makes one compromise two, and drifts the moment either
        // changes.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'tenant-password',
            'code' => $this->code(),
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_a_linked_user_is_asked_for_the_second_factor(): void
    {
        $this->link();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
        ])->assertOk();

        // Not a failure and not a success. Only reachable by somebody who
        // already has the password, so naming the reason discloses nothing — and
        // a generic rejection would have the owner retyping a correct password.
        $this->assertSame('second_factor_required', $response->json('status'));
        $this->assertNull($response->json('token'));
    }

    public function test_a_wrong_code_does_not_sign_a_linked_user_in(): void
    {
        $this->link();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => '000000',
        ])->assertOk();

        // Still not signed in — but no longer reported as a wrong PASSWORD.
        // The old 401 sent somebody who fat-fingered a digit off to reset a
        // password that was never the problem.
        $response->assertJsonPath('status', 'second_factor_required');
        $response->assertJsonMissingPath('token');
    }

    // ── what the login hands back ────────────────────────────────

    public function test_crm_login_does_not_hand_out_a_staff_token(): void
    {
        $this->link();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk();

        $tenantToken = $response->json('token');
        $this->assertNotNull($tenantToken);
        $this->assertNull($response->json('staff_token'));

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tenantToken}")
            ->getJson('/api/v1/staff/organizations')->assertForbidden();
    }

    public function test_development_unlock_with_the_authenticator_issues_a_staff_token(): void
    {
        $this->link();

        $tenantToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $staffToken = $this->withHeader('Authorization', "Bearer {$tenantToken}")
            ->postJson('/api/v1/me/development/unlock', ['code' => $this->code()])
            ->assertOk()
            ->json('token');

        $this->assertNotNull($staffToken);
        $this->assertNotSame($tenantToken, $staffToken);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->getJson('/api/v1/staff/organizations')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_development_unlock_refuses_a_wrong_code_and_an_unlinked_user(): void
    {
        $this->link();

        $tenantToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tenantToken}")
            ->postJson('/api/v1/me/development/unlock', ['code' => '000000'])
            ->assertUnauthorized();

        app(ProvisionOrganization::class)(
            organizationName: 'Plain',
            administrator: ['name' => 'P', 'email' => 'p@plain.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );
        $plainToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'p@plain.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/me/development/unlock', ['code' => '123456'])
            ->assertForbidden();
    }

    /** Per account, not only per IP, and the limit leaves a mark in the security record. */
    public function test_development_unlock_guesses_are_capped_per_account(): void
    {
        $this->link();

        $tenantToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        for ($i = 1; $i <= 10; $i++) {
            $this->app['auth']->forgetGuards();
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->withHeader('Authorization', "Bearer {$tenantToken}")
                ->postJson('/api/v1/me/development/unlock', ['code' => '000000'])
                ->assertUnauthorized();
        }

        $this->app['auth']->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('Authorization', "Bearer {$tenantToken}")
            ->postJson('/api/v1/me/development/unlock', ['code' => $this->code()])
            ->assertStatus(429);

        $this->assertSame(1, AuditEvent::withoutGlobalScopes()
            ->where('action', 'staff.development_unlock_throttled')->count());
    }

    public function test_me_reports_the_development_access_that_drives_the_sidebar(): void
    {
        $this->link();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.development.tier', PlatformStaff::TIER_LEAD);
    }

    public function test_an_unlinked_user_reports_no_development_access(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'tenant-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // The client renders the Development tab from this, so a null here is
        // what stops the tab appearing for somebody with nothing behind it.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.development', null);
    }

    // ── the link does not grant tenant powers ────────────────────

    public function test_linking_does_not_change_what_the_user_may_do_in_their_organization(): void
    {
        $this->link();

        $other = app(ProvisionOrganization::class)(
            organizationName: 'Bravo',
            administrator: ['name' => 'B', 'email' => 'b@bravo.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // The tenant token is still a tenant token. Being staff underneath does
        // not widen it — cross-organization reach arrives through the STAFF
        // token, which is audited and separately scoped.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_link_to_an_unenrolled_staff_account_is_ignored(): void
    {
        $this->staff->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();
        $this->link();

        // Otherwise disabling somebody's platform access would lock them out of
        // their own CRM as a side effect, or point their login at a credential
        // nobody can use.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'tenant-password',
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    /**
     * Jose, 2026-11-27: "if an account is deactivated they should not be able
     * to log in to ezbizi". The old CRM password, with no second factor, used
     * to reopen an account whose staff record had been switched off.
     */
    public function test_a_deactivated_staff_account_cannot_sign_in_to_the_crm_either(): void
    {
        $this->link();
        $this->staff->forceFill(['is_active' => false])->save();

        // Not by the old CRM password...
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'tenant-password',
        ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        // ...nor by the staff credentials.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        // Nor through the organization picker.
        $this->postJson('/api/v1/auth/choose', [
            'user_id' => $this->org['admin']->getKey(), 'email' => 'jose@ezbizi.test',
            'password' => 'tenant-password',
        ])->assertUnauthorized();
    }

    /** The same refusal a deactivated CRM user gets, which already held. */
    public function test_a_deactivated_crm_user_is_refused_with_the_same_answer(): void
    {
        User::withoutGlobalScopes()->whereKey($this->org['admin']->getKey())->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'tenant-password',
        ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    /** A reset link must not reopen it. */
    public function test_a_deactivated_staff_account_gets_no_reset_link_for_its_crm_login(): void
    {
        Notification::fake();
        $this->link();
        $this->staff->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/forgot', ['email' => 'jose@ezbizi.test'])->assertOk();

        Notification::assertNothingSent();
    }

    /** Deactivating ends the linked CRM sessions too, not only new sign-ins. */
    public function test_deactivating_the_staff_account_ends_its_crm_sessions(): void
    {
        $this->link();

        $crm = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        $other = PlatformStaff::create([
            'name' => 'Other Lead', 'email' => 'lead2@ezbizi.test',
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);
        $otherSecret = $this->totp->generateSecretKey(32);
        $other->forceFill(['totp_secret' => $otherSecret, 'totp_confirmed_at' => now()])->save();

        $this->app['auth']->forgetGuards();
        $leadToken = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'lead2@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->totp->getCurrentOtp($otherSecret),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$leadToken}")
            ->patchJson("/api/v1/staff/accounts/{$this->staff->id}", ['is_active' => false])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$crm}")
            ->getJson('/api/v1/me')->assertUnauthorized();
    }

    // ── linking is a deliberate, audited act ─────────────────────

    public function test_linking_through_the_api_is_lead_only_and_audited(): void
    {
        $dev = PlatformStaff::create([
            'name' => 'Dev', 'email' => 'dev@ezbizi.test',
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_DEV, 'is_active' => true,
        ]);

        $devSecret = $this->totp->generateSecretKey(32);
        $dev->forceFill(['totp_secret' => $devSecret, 'totp_confirmed_at' => now()])->save();

        $devToken = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'dev@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->totp->getCurrentOtp($devSecret),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // Linking hands a tenant login the platform's credentials. A Developer
        // who could do that could do it to any user.
        $this->withHeader('Authorization', "Bearer {$devToken}")
            ->postJson("/api/v1/staff/accounts/{$this->staff->id}/link", [
                'user_id' => $this->org['admin']->getKey(),
            ])->assertForbidden();

        $leadToken = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$leadToken}")
            ->postJson("/api/v1/staff/accounts/{$this->staff->id}/link", [
                'user_id' => $this->org['admin']->getKey(),
            ])->assertOk();

        $this->assertDatabaseHas('audit_events', ['action' => 'staff.account_linked']);
    }

    public function test_unlinking_restores_the_users_own_password(): void
    {
        $this->link();

        PlatformStaffLink::where('user_id', $this->org['admin']->getKey())->delete();

        // The tenant password was never overwritten, so unlinking cannot lock
        // anybody out of their own CRM.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'tenant-password',
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_a_user_cannot_be_linked_to_two_staff_accounts(): void
    {
        $this->link();

        $other = PlatformStaff::create([
            'name' => 'Other', 'email' => 'other@ezbizi.test',
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $otherSecret = $this->totp->generateSecretKey(32);
        $other->forceFill(['totp_secret' => $otherSecret, 'totp_confirmed_at' => now()])->save();

        $token = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'jose@ezbizi.test', 'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->code(),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // Silently repointing it would change who a login answers to without
        // anybody deciding that.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/staff/accounts/{$other->id}/link", [
                'user_id' => $this->org['admin']->getKey(),
            ])->assertStatus(422);
    }
}
