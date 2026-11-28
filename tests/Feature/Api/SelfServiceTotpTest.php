<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Services\Staff\StaffAuthenticator;
use App\Support\Auth\TotpEnrolment;
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
    /**
     * Let the next code count as new.
     *
     * Codes are single-use: verifying one records its step, and the same digits
     * are refused for the rest of that 30-second window. Signing in with a code
     * and then USING a code in the same window is two verifications, so a test
     * that does both has to say time passed — Google2FA reads the real clock and
     * Carbon cannot move it, so clearing the recorded step is how.
     */
    private function freshStep(): void
    {
        // withoutGlobalScopes because this runs between requests, where there is
        // no tenant — SetTenantContext clears it as each one finishes, and the
        // tenant read scope now refuses rather than quietly matching every row.
        User::withoutGlobalScopes()->whereKey($this->org['admin']->id)->update(['last_totp_timestamp' => null]);
    }

    private function signedIn(?string $secret = null): static
    {
        if ($secret !== null) {
            // Each code works once, so a test that signs in repeatedly needs a
            // fresh step. Google2FA reads the real clock and Carbon cannot move
            // it; clearing the recorded step is how a test says time passed.
            User::withoutGlobalScopes()->whereKey($this->org['admin']->id)->update(['last_totp_timestamp' => null]);
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

    /**
     * R8 does not stop at the customer surface.
     *
     * Both of these were bare `abort_unless(..., 403)`, so both answered with
     * Laravel's "This action is unauthorized." — which names nothing and offers
     * nothing. The reader is a person halfway through setting up an
     * authenticator, and the control plane already holds itself to this
     * standard: StaffAccountsController::authorizeManagement has written a
     * sentence since the day it was added.
     */
    public function test_a_customer_account_at_the_staff_setup_is_told_where_it_is(): void
    {
        $message = $this->signedIn()
            ->postJson('/api/v1/staff/auth/enrolment/confirm', ['code' => '000000'])
            ->assertForbidden()
            ->json('message');

        $this->assertStringNotContainsString('This action is unauthorized', $message);
        $this->assertStringContainsString('customer account', $message);
        $this->assertStringContainsString('staff sign-in screen', $message);
    }

    public function test_an_ordinary_staff_session_is_told_to_start_the_setup_again(): void
    {
        $staff = $this->staff(enrolled: true);

        // What signing in normally hands back: the `staff` ability, not `enrol`.
        $token = $staff->createToken('staff:web', ['staff'])->plainTextToken;

        $this->app['auth']->forgetGuards();

        $message = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/auth/enrolment/confirm', ['code' => '000000'])
            ->assertForbidden()
            ->json('message');

        $this->assertStringNotContainsString('This action is unauthorized', $message);

        // The commonest way to arrive here is a QR code left on screen over
        // lunch, so the sentence has to name the lifetime and the way back.
        $this->assertStringContainsString('fifteen minutes', $message);
        $this->assertStringContainsString('Start the authenticator setup again', $message);
    }

    public function test_a_pending_secret_an_administrator_created_is_reused(): void
    {
        $staff = $this->staff();

        $issued = app(TotpEnrolment::class)->begin($staff, $staff->email);

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

        User::withoutGlobalScopes()->whereKey($this->org['admin']->id)->update(['last_totp_timestamp' => null]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_starting_an_enrolment_is_recorded(): void
    {
        $this->signedIn()->postJson('/api/v1/me/totp')->assertOk();

        // The staff plane records the same moment and this side recorded nothing,
        // so `user.two_factor_enabled` arrived with no beginning to it. The gap
        // matters most in the case worth reading about later: an enrolment
        // somebody started and never confirmed.
        $this->assertSame(
            1,
            AuditEvent::where('action', 'user.two_factor_enrolment_started')->count(),
        );
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

        // Nor is the password on its own enough. Somebody at that laptop who
        // also knows the password is exactly the case the second factor exists
        // for — so removing it requires holding the thing being removed.
        $this->app['auth']->forgetGuards();
        $this->signedIn($secret)->deleteJson('/api/v1/me/totp', [
            'password' => 'a-real-password',
        ])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $request = $this->signedIn($secret);
        $this->freshStep();
        $request->deleteJson('/api/v1/me/totp', [
            'password' => 'a-real-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        // withoutGlobalScopes for the same reason as freshStep(): between
        // requests there is no tenant, so a scoped read has nothing to scope by.
        $this->assertFalse(User::withoutGlobalScopes()->find($this->org['admin']->id)->hasTotp());
    }

    /**
     * A stolen session cannot grind the password and code here (audit
     * 2026-11-27): without a limit, "that password is not right" confirms a
     * hit, then the code can be ground, then the factor switched off.
     */
    public function test_proving_it_is_you_is_throttled_per_user(): void
    {
        $begin = $this->signedIn()->postJson('/api/v1/me/totp')->assertOk();
        $secret = $begin->json('enrolment.secret');

        $this->app['auth']->forgetGuards();
        $this->signedIn()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $stolen = $this->signedIn($secret);

        for ($i = 0; $i < 5; $i++) {
            $stolen->deleteJson('/api/v1/me/totp', ['password' => "guess-{$i}"])->assertStatus(422);
        }

        // Even the right password and a right code are refused now.
        $this->freshStep();
        $stolen->deleteJson('/api/v1/me/totp', [
            'password' => 'a-real-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertStatus(429);

        $this->assertTrue(User::withoutGlobalScopes()->find($this->org['admin']->id)->hasTotp());
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()
            ->where('action', 'user.two_factor_reauth_throttled')->count());
    }

    /**
     * One code, one yes — even for two requests that read the account before
     * either wrote (audit 2026-11-27: the replay check was read-then-save).
     * Two copies of the model loaded before either verifies are exactly that.
     */
    public function test_a_code_is_accepted_once_even_by_two_racing_requests(): void
    {
        $secret = $this->totp->generateSecretKey(32);
        $id = $this->org['admin']->id;

        app(TenantContext::class)->run($this->org['organization'], fn () => User::findOrFail($id)
            ->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save());

        $first = User::withoutGlobalScopes()->findOrFail($id);
        $second = User::withoutGlobalScopes()->findOrFail($id);
        $code = $this->totp->getCurrentOtp($secret);

        $enrolment = app(TotpEnrolment::class);
        $this->assertTrue($enrolment->verify($first, $code));
        $this->assertFalse($enrolment->verify($second, $code), 'one code signed in twice');

        $staff = $this->staff(enrolled: true);
        $staffSecret = $staff->totp_secret;
        $a = PlatformStaff::findOrFail($staff->id);
        $b = PlatformStaff::findOrFail($staff->id);
        $staffCode = $this->totp->getCurrentOtp($staffSecret);

        $auth = app(StaffAuthenticator::class);
        $this->assertTrue($auth->verifySecondFactor($a, $staffCode));
        $this->assertFalse($auth->verifySecondFactor($b, $staffCode), 'one staff code signed in twice');
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
        // was set above them. Proving who they are is not the obstacle here —
        // the policy is — so this sends a valid code as well.
        $this->app['auth']->forgetGuards();
        $request = $this->signedIn($secret);
        $this->freshStep();
        $request->deleteJson('/api/v1/me/totp', [
            'password' => 'a-real-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertStatus(422);
    }

    public function test_state_is_reported_so_the_settings_screen_knows_what_to_show(): void
    {
        $this->signedIn()->getJson('/api/v1/me/totp')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.pending', false)
            ->assertJsonPath('data.covers_control_plane', false);
    }

    public function test_moving_to_a_new_phone_does_not_require_going_unprotected_first(): void
    {
        $begin = $this->signedIn()->postJson('/api/v1/me/totp')->assertOk();
        $secret = $begin->json('enrolment.secret');

        $this->app['auth']->forgetGuards();
        $this->signedIn()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        // Re-enrolling used to mean "turn it off first", which leaves a window
        // with no second factor at all — on an account the organization may
        // require one for.
        $this->app['auth']->forgetGuards();
        $request = $this->signedIn($secret);
        $this->freshStep();
        $second = $request->postJson('/api/v1/me/totp', [
            'password' => 'a-real-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        $newSecret = $second->json('enrolment.secret');

        $this->assertNotSame($secret, $newSecret);

        // The old one still works until the new one is confirmed.
        $this->assertTrue(User::withoutGlobalScopes()->find($this->org['admin']->id)->hasTotp());

        $this->app['auth']->forgetGuards();
        $this->signedIn($secret)->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($newSecret),
        ])->assertOk();
    }

    public function test_re_enrolling_needs_the_current_code_too(): void
    {
        $begin = $this->signedIn()->postJson('/api/v1/me/totp')->assertOk();
        $secret = $begin->json('enrolment.secret');

        $this->app['auth']->forgetGuards();
        $this->signedIn()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        // Otherwise a borrowed laptop plus a known password is enough to point
        // the second factor at an attacker's own phone.
        $this->app['auth']->forgetGuards();
        $this->signedIn($secret)->postJson('/api/v1/me/totp', [
            'password' => 'a-real-password',
        ])->assertStatus(422);
    }
}
