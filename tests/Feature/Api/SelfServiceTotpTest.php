<?php

namespace Tests\Feature\Api;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Setting up a second factor without anybody else present (§4.2, §6).
 *
 * Two audiences, one mechanism:
 *
 *   Staff  cannot sign in until enrolled, so they need a bootstrap path that
 *          does not require a console or somebody sitting with them.
 *   Users  are already signed in, so theirs is an ordinary settings action —
 *          and it is what lets a client switch 2FA on for themselves.
 */
class SelfServiceTotpTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;
    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = app(Google2FA::class);

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = ['organization' => $r['organization'], 'admin' => $r['administrator']];
    }

    private function staff(bool $enrolled = false): PlatformStaff
    {
        $staff = PlatformStaff::create([
            'name' => 'New Person', 'email' => 'new@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_DEV, 'is_active' => true,
        ]);

        if ($enrolled) {
            $staff->forceFill([
                'totp_secret' => $this->totp->generateSecretKey(32),
                'totp_confirmed_at' => now(),
            ])->save();
        }

        return $staff;
    }

    /**
     * @param  string|null  $secret  once 2FA is on, signing in needs a code —
     *                               which is the whole point, so the helper has
     *                               to keep up rather than the feature bend.
     */
    private function signedIn(?string $secret = null): static
    {
        if ($secret !== null) {
            // Each code works once, so a test that signs in repeatedly needs a
            // fresh step. Google2FA reads the real clock and Carbon cannot move
            // it; clearing the recorded step is how a test says time passed.
            User::whereKey($this->org['admin']->id)->update(['last_totp_timestamp' => null]);
        }

        $token = $this->postJson('/api/v1/auth/login', array_filter([
            'email' => 'a@alpha.test',
            'password' => 'a-real-password',
            'code' => $secret === null ? null : $this->totp->getCurrentOtp($secret),
        ]))->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ── staff bootstrap ──────────────────────────────────────────

    public function test_a_new_staff_member_can_enrol_themselves(): void
    {
        $this->staff();

        $begin = $this->postJson('/api/v1/staff/auth/enrolment', [
            'email' => 'new@ezbizi.test', 'password' => 'a-sufficiently-long-password',
        ])->assertOk();

        $secret = $begin->json('enrolment.secret');
        $this->assertStringStartsWith('otpauth://totp/', $begin->json('enrolment.uri'));

        $this->app['auth']->forgetGuards();

        $confirm = $this->withHeader('Authorization', 'Bearer '.$begin->json('token'))
            ->postJson('/api/v1/staff/auth/enrolment/confirm', [
                'code' => $this->totp->getCurrentOtp($secret),
            ])->assertOk();

        $this->assertCount(8, $confirm->json('recovery_codes'));

        // And now the account is a working one — no console, nobody else present.
        PlatformStaff::where('email', 'new@ezbizi.test')->update(['last_totp_timestamp' => null]);

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'new@ezbizi.test', 'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();
    }

    public function test_an_already_enrolled_account_cannot_be_re_bound_here(): void
    {
        $this->staff(enrolled: true);

        // Identical wording to a bad password, on purpose: this route is
        // unauthenticated, and "already enrolled" would confirm the address
        // belongs to a working staff account.
        $this->postJson('/api/v1/staff/auth/enrolment', [
            'email' => 'new@ezbizi.test', 'password' => 'a-sufficiently-long-password',
        ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_a_wrong_password_gets_nothing_and_is_recorded(): void
    {
        $this->staff();

        $this->postJson('/api/v1/staff/auth/enrolment', [
            'email' => 'new@ezbizi.test', 'password' => 'not-the-password',
        ])->assertUnauthorized();

        // This is exactly the endpoint somebody would probe, so a failure has to
        // leave a trace beyond a rate-limiter counter that expires.
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.enrolment_refused']);
    }

    public function test_the_enrolment_session_can_do_nothing_else(): void
    {
        $this->staff();

        $token = $this->postJson('/api/v1/staff/auth/enrolment', [
            'email' => 'new@ezbizi.test', 'password' => 'a-sufficiently-long-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // One ability, fifteen minutes. It cannot read an organization, cannot
        // open a customer, cannot do anything but finish enrolling.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/organizations')->assertForbidden();
    }

    public function test_a_pending_secret_an_administrator_created_is_reused(): void
    {
        $staff = $this->staff();

        $issued = app(\App\Support\Auth\TotpEnrolment::class)->begin($staff, $staff->email);

        $begin = $this->postJson('/api/v1/staff/auth/enrolment', [
            'email' => 'new@ezbizi.test', 'password' => 'a-sufficiently-long-password',
        ])->assertOk();

        // Generating a fresh one would invalidate whatever is already on their
        // phone — which is the most confusing possible outcome for somebody who
        // has just been sent a QR.
        $this->assertSame($issued['secret'], $begin->json('enrolment.secret'));
    }

    // ── tenant users ─────────────────────────────────────────────

    public function test_a_client_can_switch_two_factor_on_for_themselves(): void
    {
        $begin = $this->signedIn()->postJson('/api/v1/me/totp')->assertOk();

        $secret = $begin->json('enrolment.secret');

        // Still signs in exactly as before until the code proves it — a mis-scan
        // must not lock somebody out of their own CRM.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->assertJsonPath('status', 'authenticated');

        $this->app['auth']->forgetGuards();

        $confirm = $this->signedIn()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        $this->assertCount(8, $confirm->json('recovery_codes'));

        // Now it is required.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->assertJsonPath('status', 'second_factor_required');

        User::whereKey($this->org['admin']->id)->update(['last_totp_timestamp' => null]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
            'code'  => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_turning_it_off_needs_the_password(): void
    {
        $begin = $this->signedIn()->postJson('/api/v1/me/totp')->assertOk();

        $secret = $begin->json('enrolment.secret');

        $this->app['auth']->forgetGuards();
        $this->signedIn()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        // A borrowed unlocked laptop must not be able to strip the very thing
        // protecting the account from that laptop.
        $this->app['auth']->forgetGuards();
        $this->signedIn($secret)->deleteJson('/api/v1/me/totp', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->signedIn($secret)->deleteJson('/api/v1/me/totp', ['password' => 'a-real-password'])
            ->assertOk();

        $this->assertFalse(User::find($this->org['admin']->id)->hasTotp());
    }

    public function test_a_user_cannot_opt_out_of_a_policy_their_organization_set(): void
    {
        $begin = $this->signedIn()->postJson('/api/v1/me/totp')->assertOk();

        $secret = $begin->json('enrolment.secret');

        $this->app['auth']->forgetGuards();
        $this->signedIn()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        app(TenantContext::class)->run($this->org['organization'], fn () => app(SettingsResolver::class)
            ->set('security.require_two_factor', $this->org['organization'], true));

        // §6: allowed scopes exist precisely so somebody below cannot weaken what
        // was set above them.
        $this->app['auth']->forgetGuards();
        $this->signedIn($secret)->deleteJson('/api/v1/me/totp', ['password' => 'a-real-password'])
            ->assertStatus(422);
    }

    public function test_state_is_reported_so_the_settings_screen_knows_what_to_show(): void
    {
        $this->signedIn()->getJson('/api/v1/me/totp')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.pending', false)
            ->assertJsonPath('data.managed_elsewhere', false);
    }
}
