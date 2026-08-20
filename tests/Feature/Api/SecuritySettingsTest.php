<?php

namespace Tests\Feature\Api;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\SessionLifetime;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The §6 security settings, doing what the Settings screen says they do.
 *
 * Every test here changes a SETTING and observes the behaviour change. That is
 * the point: all four of these were declared, saved, audited and read by
 * nothing, which is worse than their being absent — the screen presented them
 * as enforced.
 *
 * `security.password_expiry_days` is deliberately not tested, because it is
 * deliberately not wired. There is no `password_changed_at` anywhere to measure
 * an age against, so it needs a column, a migration and a forced-change flow
 * before there is any behaviour to assert. It is left to be marked pending on
 * the Settings screen rather than half-built here.
 */
class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;
    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = app(Google2FA::class);

        $tree = app(ProvisionOrganization::class)(
            organizationName: 'Everdoors',
            administrator: [
                'name' => 'Owner', 'email' => 'owner@everdoors.test',
                'password' => 'a-real-password',
            ],
            companyName: 'Everdoors Co', franchiseName: 'Connecticut',
        );

        $this->org = ['organization' => $tree['organization'], 'admin' => $tree['administrator']];
    }

    /** Set an organization-level setting, from outside any request. */
    private function set(string $key, mixed $value): void
    {
        app(TenantContext::class)->run(
            $this->org['organization'],
            fn () => app(SettingsResolver::class)->set($key, $this->org['organization'], $value),
        );
    }

    /** Sign in and return a request builder carrying the token. */
    private function signedIn(?string $secret = null, string $password = 'a-real-password'): static
    {
        if ($secret !== null) {
            // Codes are single-use: verifying one records its time-step and the
            // same digits are refused for the rest of that window. Google2FA
            // reads the real clock and Carbon cannot move it, so clearing the
            // recorded step is how a test says time passed.
            User::withoutGlobalScopes()
                ->whereKey($this->org['admin']->id)
                ->update(['last_totp_timestamp' => null]);
        }

        $token = $this->postJson('/api/v1/auth/login', array_filter([
            'email' => 'owner@everdoors.test',
            'password' => $password,
            'code' => $secret === null ? null : $this->totp->getCurrentOtp($secret),
        ]))->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Enrol the administrator's own authenticator and return the secret. */
    private function enrol(): string
    {
        $secret = $this->signedIn()->postJson('/api/v1/me/totp')->assertOk()
            ->json('enrolment.secret');

        $this->app['auth']->forgetGuards();

        $this->signedIn()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        return $secret;
    }

    // ─────────────────────────────────────────────────────────────
    // security.require_two_factor
    //
    // The dangerous one. Production holds exactly one account — the owner's —
    // and the recovery path from a lockout is a password reset email, which
    // this platform cannot send (TODO #1). So the first test below is the one
    // that had to pass before the enforcement existed at all.
    // ─────────────────────────────────────────────────────────────

    /**
     * THE LOCKOUT TEST.
     *
     * Switch the requirement on for somebody who has no authenticator, and every
     * step of the way back must stay open: signing in, being told, fetching the
     * payload the client needs to draw anything, starting enrolment, finishing
     * it, and then finding the app working.
     *
     * If this test ever goes red, the owner cannot get into their own platform
     * and there is no email to rescue them with.
     */
    public function test_requiring_two_factor_never_locks_an_unenrolled_user_out(): void
    {
        $this->set('security.require_two_factor', true);

        // 1. Sign-in still works. It is NOT a refusal, and it is not even a
        //    second-factor challenge — there is no factor to challenge.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@everdoors.test', 'password' => 'a-real-password',
        ])->assertOk();

        $login->assertJsonPath('status', 'authenticated');

        // 2. And it says so, so the client can route straight to enrolment
        //    instead of letting somebody discover it via a 403 dashboard.
        $login->assertJsonPath('two_factor_enrolment_required', true);

        $token = $login->json('token');
        $this->app['auth']->forgetGuards();

        $as = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        // 3. The launch payload. Without this the client cannot draw a screen at
        //    all, so refusing it would replace the whole app with a sign-in form
        //    that will not help.
        $as()->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.two_factor_enrolment_required', true);

        $this->app['auth']->forgetGuards();

        // 4. Enrolment is reachable, and asks for nothing an un-enrolled person
        //    cannot supply.
        $secret = $as()->postJson('/api/v1/me/totp')->assertOk()->json('enrolment.secret');
        $this->assertNotNull($secret);

        $this->app['auth']->forgetGuards();

        // 5. The account screen around it loads too — it is one page, and an
        //    error where the session list should be takes the enrolment control
        //    down with it.
        $as()->getJson('/api/v1/me/sessions')->assertOk();
        $this->app['auth']->forgetGuards();
        $as()->getJson('/api/v1/me/profile')->assertOk();
        $this->app['auth']->forgetGuards();

        // 6. Confirming works, on the same token.
        $as()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // 7. And now the app is open, without signing in again.
        $as()->getJson('/api/v1/customers')->assertOk();

        $this->app['auth']->forgetGuards();
        $as()->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.two_factor_enrolment_required', false);
    }

    /** Signing out must work too — being unable to leave is its own trap. */
    public function test_an_unenrolled_user_can_still_sign_out(): void
    {
        $this->set('security.require_two_factor', true);

        $this->signedIn()->postJson('/api/v1/auth/logout', [])->assertOk();
    }

    /** The setting is what changes the behaviour — off, nothing is refused. */
    public function test_business_data_is_closed_only_while_the_setting_demands_it(): void
    {
        // Off (the default): an account with no authenticator works normally.
        $this->signedIn()->getJson('/api/v1/customers')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->set('security.require_two_factor', true);

        $this->signedIn()->getJson('/api/v1/customers')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_enrolment_required');

        $this->app['auth']->forgetGuards();

        // Writes as well as reads, and the Settings screen itself — otherwise
        // "enforced" means "readable by anybody who skips the dashboard".
        $this->signedIn()->getJson(
            '/api/v1/settings?scope_type=organization&scope_id='.$this->org['organization']->id
        )->assertForbidden();
    }

    /** Enrol, and the refusal stops — the same session, no re-login. */
    public function test_enrolling_lifts_the_refusal(): void
    {
        $this->set('security.require_two_factor', true);

        $secret = $this->enrol();

        $this->signedIn($secret)->getJson('/api/v1/customers')->assertOk();
    }

    /**
     * A linked account's factor lives on the STAFF record (§4.2), and the
     * requirement has to look there.
     *
     * This is not a corner case here: the single account in production is the
     * owner's, and it is linked. Asking the user row instead would tell somebody
     * with a perfectly good authenticator that they had not enrolled, refuse
     * them the app, and send them to a card that manages a different record —
     * a lockout produced entirely by looking in the wrong place.
     */
    public function test_a_linked_account_is_judged_on_its_staff_authenticator(): void
    {
        $secret = $this->totp->generateSecretKey(32);

        $staff = PlatformStaff::create([
            'name' => 'Owner', 'email' => 'owner@everdoors.test',
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $staff->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        PlatformStaffLink::create([
            'staff_id' => $staff->getKey(),
            'user_id'  => $this->org['admin']->getKey(),
        ]);

        $this->set('security.require_two_factor', true);

        // The USER row has no authenticator and never will — it is not the one
        // that signs this person in.
        $this->assertFalse(
            User::withoutGlobalScopes()->find($this->org['admin']->id)->hasTotp()
        );

        PlatformStaff::whereKey($staff->getKey())->update(['last_totp_timestamp' => null]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@everdoors.test',
            'password' => 'a-sufficiently-long-staff-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()
            ->assertJsonPath('two_factor_enrolment_required', false)
            ->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customers')->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // security.password_min_length
    // ─────────────────────────────────────────────────────────────

    /** Raise it, and a password the platform floor would accept is refused. */
    public function test_raising_the_minimum_password_length_is_enforced_on_change(): void
    {
        // Thirteen characters: over the platform floor of 12, under the 16 the
        // organization is about to ask for. Nothing but the SETTING can be the
        // reason this is refused.
        $thirteen = 'abcdefghijklm';
        $sixteen  = 'abcdefghijklmnop';

        $this->assertGreaterThan(PasswordPolicy::MIN_LENGTH, strlen($thirteen));

        $this->signedIn()->postJson('/api/v1/me/password', [
            'current_password' => 'a-real-password',
            'password' => $thirteen, 'password_confirmation' => $thirteen,
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->set('security.password_min_length', 16);

        $this->signedIn(password: $thirteen)->postJson('/api/v1/me/password', [
            'current_password' => $thirteen,
            'password' => 'abcdefghijklmn', 'password_confirmation' => 'abcdefghijklmn',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->app['auth']->forgetGuards();

        $this->signedIn(password: $thirteen)->postJson('/api/v1/me/password', [
            'current_password' => $thirteen,
            'password' => $sixteen, 'password_confirmation' => $sixteen,
        ])->assertOk();
    }

    /**
     * The floor is a floor. PasswordPolicy::MIN_LENGTH is a §2.1 security
     * invariant, and an administrator setting 4 must not be able to move it.
     */
    public function test_the_setting_cannot_lower_the_platform_floor(): void
    {
        $this->set('security.password_min_length', 4);

        $this->assertSame(
            PasswordPolicy::MIN_LENGTH,
            PasswordPolicy::minLengthFor($this->org['organization']),
        );

        $this->signedIn()->postJson('/api/v1/me/password', [
            'current_password' => 'a-real-password',
            'password' => 'short1!', 'password_confirmation' => 'short1!',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * The reset screen has no session, so it would be the way around the policy
     * if it were left at the platform floor: an administrator sets 16 and
     * anybody who wants twelve characters asks for a reset link instead.
     */
    public function test_the_minimum_applies_to_a_password_reset_too(): void
    {
        $this->set('security.password_min_length', 16);

        $token = app(\App\Services\Auth\PasswordResets::class)
            ->issue($this->org['admin'])['token'];

        $thirteen = 'abcdefghijklm';

        $this->postJson('/api/v1/auth/reset', [
            'token' => $token,
            'password' => $thirteen, 'password_confirmation' => $thirteen,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // And the refusal did not spend the token — somebody who typed a short
        // password must not have to ask for a new link.
        $this->postJson('/api/v1/auth/reset', [
            'token' => $token,
            'password' => 'abcdefghijklmnop', 'password_confirmation' => 'abcdefghijklmnop',
        ])->assertOk();
    }

    /**
     * Signup cannot consult it, and that asymmetry is intentional — there is no
     * organization to read a setting against while the form that creates one is
     * being validated.
     *
     * Pinned so that nobody "fixes" it by inventing a global default, and so the
     * next person to read PasswordPolicy's docblock finds the claim tested.
     */
    public function test_signup_is_governed_by_the_platform_floor_alone(): void
    {
        $this->assertSame(PasswordPolicy::MIN_LENGTH, PasswordPolicy::minLengthFor(null));

        $twelve = 'abcdefghijkl';

        $this->postJson('/api/v1/auth/signup', [
            'name' => 'Second Owner', 'email' => 'second@elsewhere.test',
            'password' => $twelve,
            'business_name' => 'Elsewhere', 'first_location' => 'Main',
        ])->assertCreated();
    }

    // ─────────────────────────────────────────────────────────────
    // security.session_timeout_minutes
    // ─────────────────────────────────────────────────────────────

    /** The setting decides how far ahead a fresh token's expiry sits. */
    public function test_the_idle_window_decides_a_new_tokens_expiry(): void
    {
        $this->set('security.session_timeout_minutes', 30);

        $this->signedIn();

        $token = PersonalAccessToken::latest('id')->first();

        // A window either side of 30 minutes, because the clock moves between
        // the request and the assertion. Wide enough not to flake, far too
        // narrow to be satisfied by the thirty-day expiry this replaced.
        $this->assertEqualsWithDelta(
            30 * 60,
            now()->diffInSeconds($token->expires_at),
            60,
        );
    }

    /** Change the setting, and the sessions already open follow it. */
    public function test_an_open_session_is_re_windowed_on_its_next_request(): void
    {
        $this->set('security.session_timeout_minutes', 480);

        $as = $this->signedIn();
        $token = PersonalAccessToken::latest('id')->first();

        $this->assertEqualsWithDelta(480 * 60, now()->diffInSeconds($token->expires_at), 60);

        // Tightened by an administrator answering a questionnaire. The window on
        // an existing session has to shorten, or the policy applies to nobody who
        // was already signed in.
        $this->set('security.session_timeout_minutes', 15);

        $as->getJson('/api/v1/me')->assertOk();

        $this->assertEqualsWithDelta(
            15 * 60,
            now()->diffInSeconds($token->fresh()->expires_at),
            60,
        );
    }

    /**
     * Idle means idle. A token last touched longer ago than the window is
     * refused — by Sanctum, which is where the expiry it reads was put.
     */
    public function test_a_session_left_idle_past_the_window_is_refused(): void
    {
        $this->set('security.session_timeout_minutes', 30);

        $as = $this->signedIn();
        $token = PersonalAccessToken::latest('id')->first();

        // Standing in for half an hour of nobody touching the keyboard. Written
        // directly rather than by travelling the clock, because Sanctum reads
        // `now()` too and moving it would move both ends of the comparison.
        DB::table('personal_access_tokens')->where('id', $token->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->app['auth']->forgetGuards();

        $as->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * "Keep me signed in" is a server lifetime, not only a browser persist flag.
     *
     * The idle setting would otherwise expire the token overnight while
     * localStorage still held it, so the person came back to a login screen.
     */
    public function test_keep_me_signed_in_uses_the_absolute_ceiling_not_the_idle_window(): void
    {
        $this->set('security.session_timeout_minutes', 30);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@everdoors.test',
            'password' => 'a-real-password',
            'remember' => true,
            'device_name' => 'web',
        ])->assertOk();

        $token = PersonalAccessToken::latest('id')->first();

        $this->assertNotNull($token);
        $this->assertStringEndsWith(':remember', (string) $token->name);
        $this->assertEqualsWithDelta(
            SessionLifetime::ABSOLUTE_DAYS * 24 * 60 * 60,
            now()->diffInSeconds($token->expires_at),
            60,
        );
    }

    /** An administrator tightening idle must not evict remembered sessions. */
    public function test_a_remembered_session_is_not_re_windowed_to_the_idle_setting(): void
    {
        $this->set('security.session_timeout_minutes', 15);

        $plain = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@everdoors.test',
            'password' => 'a-real-password',
            'remember' => true,
            'device_name' => 'web',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->getJson('/api/v1/me')
            ->assertOk();

        $token = PersonalAccessToken::latest('id')->first();

        $this->assertEqualsWithDelta(
            SessionLifetime::ABSOLUTE_DAYS * 24 * 60 * 60,
            now()->diffInSeconds($token->fresh()->expires_at),
            60,
        );
    }

    /** No setting may push a session past the absolute ceiling. */
    public function test_no_session_outlives_the_absolute_ceiling(): void
    {
        // Sixty days, asked for in minutes.
        $this->set('security.session_timeout_minutes', 60 * 24 * 60);

        $this->signedIn();

        $token = PersonalAccessToken::latest('id')->first();

        $this->assertEqualsWithDelta(
            SessionLifetime::ABSOLUTE_DAYS * 24 * 60 * 60,
            now()->diffInSeconds($token->expires_at),
            60,
        );
    }

    /**
     * A mistyped window must leave time to undo it.
     *
     * On a platform that cannot send a password reset email, a one-minute idle
     * timeout would be unrecoverable for the single production account.
     */
    public function test_an_unusably_short_window_is_clamped_to_the_recovery_margin(): void
    {
        $this->set('security.session_timeout_minutes', 1);

        $this->assertSame(
            SessionLifetime::MINIMUM_IDLE_MINUTES,
            app(SessionLifetime::class)->idleMinutesFor($this->org['organization']),
        );
    }

    /**
     * The lockout the no-lockout test did not catch: an ABANDONED enrolment.
     *
     * Measured before the fix. Turn the setting on, sign in, press "Turn on
     * two-factor", then close the panel without confirming — a mis-scan, the
     * wrong app, an interruption. Server state is now enabled:false,
     * pending:true. The card reads only `enabled`, so it still offers "Turn on"
     * and posts no password; `begin` saw a pending secret, demanded
     * re-authentication, and answered 422 "the password field is required"
     * against a field the card does not draw. Every other page was already 403.
     *
     * There was no way out: not Settings, not signing out and back in, and not a
     * password reset, because nothing on this platform can send mail.
     *
     * The neighbouring test calls POST /me/totp exactly once, which is why it
     * passed throughout. This one calls it twice, which is the whole bug.
     */
    public function test_an_abandoned_enrolment_can_be_restarted(): void
    {
        $this->set('security.require_two_factor', true);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@everdoors.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $as = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        // Start, then walk away. Nothing confirms it.
        $as()->postJson('/api/v1/me/totp')->assertOk();
        $this->app['auth']->forgetGuards();

        $as()->getJson('/api/v1/me/totp')->assertOk()->assertJsonPath('data.pending', true);
        $this->app['auth']->forgetGuards();

        // Coming back must work, with no password — there is nothing proven to
        // protect. A pending secret has never had a code entered against it, so
        // discarding it takes nothing away from anybody.
        $secret = $as()->postJson('/api/v1/me/totp')
            ->assertOk()
            ->json('enrolment.secret');

        $this->assertNotNull($secret, 'A second attempt at enrolment was refused.');
        $this->app['auth']->forgetGuards();

        // And it genuinely finishes, so this is a route out and not just a 200.
        $as()->postJson('/api/v1/me/totp/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $as()->getJson('/api/v1/customers')->assertOk();
    }

    /**
     * The other half: a CONFIRMED factor still cannot be replaced without the
     * password. Fixing the lockout must not open that door.
     */
    public function test_replacing_a_confirmed_factor_still_needs_the_password(): void
    {
        $secret = $this->enrol();

        $this->signedIn($secret)
            ->postJson('/api/v1/me/totp')
            ->assertStatus(422);
    }
}
