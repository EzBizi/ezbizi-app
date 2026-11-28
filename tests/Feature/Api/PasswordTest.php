<?php

namespace Tests\Feature\Api;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use App\Services\Auth\PasswordResets;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Forgotten and changed passwords (plan §4, §4.2).
 *
 * The three things a reset must not do, each with a test: tell a stranger
 * whether an address is registered, bypass the second factor, or survive its
 * own use.
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'the-old-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = ['organization' => $r['organization'], 'admin' => $r['administrator']];
    }

    private function as(string $password = 'the-old-password'): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => $password,
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ── forgotten ────────────────────────────────────────────────

    public function test_the_answer_is_the_same_whether_or_not_the_address_exists(): void
    {
        Notification::fake();

        $real = $this->postJson('/api/v1/auth/forgot', ['email' => 'a@alpha.test'])->assertOk();
        $fake = $this->postJson('/api/v1/auth/forgot', ['email' => 'nobody@nowhere.test'])->assertOk();

        // Telling a stranger which addresses are registered is a customer list
        // and an employment record — the same reason login never says which half
        // was wrong.
        $this->assertSame($real->json('message'), $fake->json('message'));

        Notification::assertCount(1);
    }

    public function test_a_link_resets_the_password_and_ends_every_session(): void
    {
        $token = app(PasswordResets::class)->issue($this->alpha['admin'])['token'];

        // A live session from before the reset.
        $existing = $this->as()->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/auth/reset', [
            'token' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        // withoutGlobalScopes: the assertion runs after the request has ended,
        // and SetTenantContext clears the tenant on its way out — so this read
        // has no tenant to be scoped by and has to say so.
        $this->assertTrue(Hash::check(
            'a-brand-new-password',
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)->password,
        ));

        // The usual reason somebody resets is that they think the old password
        // is known. Leaving sessions alive would make the reset cosmetic.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_token_works_once(): void
    {
        $token = app(PasswordResets::class)->issue($this->alpha['admin'])['token'];

        $this->postJson('/api/v1/auth/reset', [
            'token' => $token, 'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset', [
            'token' => $token, 'password' => 'another-new-password',
            'password_confirmation' => 'another-new-password',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_token');
    }

    public function test_asking_again_invalidates_the_first_link(): void
    {
        $resets = app(PasswordResets::class);

        $first = $resets->issue($this->alpha['admin'])['token'];
        $resets->issue($this->alpha['admin']);

        // Two live tokens means an old email sitting in a mailbox is still a way
        // in, long after somebody asked for a fresh one.
        $this->postJson('/api/v1/auth/reset', [
            'token' => $first, 'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422);
    }

    public function test_a_reset_does_not_get_you_past_the_second_factor(): void
    {
        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        User::withoutGlobalScopes()->whereKey($this->alpha['admin']->id)->update([
            // encryptString, not encrypt(). The `encrypted` cast reads with
            // decryptString, which does not unserialize — encrypt() wraps a
            // SERIALIZED value, so the secret came back as `s:32:"..."` and
            // base32 decoding threw. It stayed hidden because no test had
            // ever verified a user's own code, only asked for one.
            //
            // Straight to the row, on purpose: no tenant here, and a model save
            // would go through BelongsToTenant's write guard. withoutGlobalScopes
            // because User::whereKey() is an Eloquent builder, so the tenant READ
            // scope applies to it as well — it used to pass by failing open.
            'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(),
        ]);

        $token = app(PasswordResets::class)->issue($this->alpha['admin']->fresh())['token'];

        $response = $this->postJson('/api/v1/auth/reset', [
            'token' => $token, 'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        // Resetting a password proves control of a mailbox. It proves nothing
        // about the authenticator, and this is the most common place an
        // implementation quietly drops the second factor.
        $this->assertTrue($response->json('needs_second_factor'));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-brand-new-password',
        ])->assertOk()->assertJsonPath('status', 'second_factor_required');
    }

    public function test_a_short_password_is_refused(): void
    {
        $token = app(PasswordResets::class)->issue($this->alpha['admin'])['token'];

        $this->postJson('/api/v1/auth/reset', [
            'token' => $token, 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    // ── changed while signed in ──────────────────────────────────

    public function test_changing_it_needs_the_current_one(): void
    {
        // A session proves somebody sat down at an unlocked laptop, not that
        // they are the account holder.
        $this->as()->postJson('/api/v1/me/password', [
            'current_password' => 'not-it',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
    }

    public function test_changing_it_keeps_this_session_and_drops_the_others(): void
    {
        // A second device.
        $other = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'the-old-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->as()->postJson('/api/v1/me/password', [
            'current_password' => 'the-old-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Being logged out of the browser you just used is confusing; being left
        // signed in everywhere else is the actual risk.
        $this->withHeader('Authorization', "Bearer {$other}")
            ->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-brand-new-password',
        ])->assertOk();
    }

    public function test_reusing_the_same_password_is_refused(): void
    {
        $this->as()->postJson('/api/v1/me/password', [
            'current_password' => 'the-old-password',
            'password' => 'the-old-password',
            'password_confirmation' => 'the-old-password',
        ])->assertStatus(422)->assertJsonPath('code', 'unchanged');
    }

    // ── linked accounts (§4.2, revised) ──────────────────────────

    public function test_a_linked_account_changes_the_staff_password(): void
    {
        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => 'a@alpha.test',
            'password' => 'the-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $staff->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        PlatformStaffLink::create([
            'staff_id' => $staff->getKey(), 'user_id' => $this->alpha['admin']->getKey(),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'the-staff-password',
            'code' => $totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/me/password', [
                'current_password' => 'the-staff-password',
                'password' => 'a-brand-new-staff-password',
                'password_confirmation' => 'a-brand-new-staff-password',
            ])->assertOk();

        // Writing it to the user row would change a value nothing reads, and
        // leave somebody believing they had changed their password.
        $this->assertTrue(Hash::check('a-brand-new-staff-password', $staff->fresh()->password));
    }

    public function test_a_reset_for_a_linked_address_targets_the_staff_record(): void
    {
        Notification::fake();

        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => 'a@alpha.test',
            'password' => 'the-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $staff->forceFill([
            'totp_secret' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ])->save();

        PlatformStaffLink::create([
            'staff_id' => $staff->getKey(), 'user_id' => $this->alpha['admin']->getKey(),
        ]);

        $this->postJson('/api/v1/auth/forgot', ['email' => 'a@alpha.test'])->assertOk();

        // ONE email, not two. The user row's password is not the one that signs
        // them in, so resetting it as well would send a link that changes
        // nothing anybody uses.
        Notification::assertCount(1);
        Notification::assertSentOnDemand(PasswordResetLink::class);
    }

    // ── a staff password covers its linked CRM sessions (audit 2026-11-27) ──

    private function linkedStaff(): array
    {
        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => 'a@alpha.test',
            'password' => 'the-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $staff->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        PlatformStaffLink::create([
            'staff_id' => $staff->getKey(), 'user_id' => $this->alpha['admin']->getKey(),
        ]);

        return [$staff, $secret];
    }

    private function crmToken(PlatformStaff $staff, string $secret): string
    {
        PlatformStaff::whereKey($staff->getKey())->update(['last_totp_timestamp' => null]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'the-staff-password',
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_a_staff_reset_ends_the_linked_crm_sessions_too(): void
    {
        [$staff, $secret] = $this->linkedStaff();
        $stolen = $this->crmToken($staff, $secret);

        $token = app(PasswordResets::class)->issue($staff)['token'];

        $this->postJson('/api/v1/auth/reset', [
            'token' => $token,
            'password' => 'a-brand-new-staff-password',
            'password_confirmation' => 'a-brand-new-staff-password',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // The reset exists because the old password may be known. A CRM token
        // minted from that password is the same credential's session.
        $this->withHeader('Authorization', "Bearer {$stolen}")
            ->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_linked_password_change_keeps_this_crm_session_and_ends_the_others(): void
    {
        [$staff, $secret] = $this->linkedStaff();
        $other = $this->crmToken($staff, $secret);
        $here = $this->crmToken($staff, $secret);

        $this->withHeader('Authorization', "Bearer {$here}")
            ->postJson('/api/v1/me/password', [
                'current_password' => 'the-staff-password',
                'password' => 'a-brand-new-staff-password',
                'password_confirmation' => 'a-brand-new-staff-password',
            ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$other}")
            ->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$here}")
            ->getJson('/api/v1/me')->assertOk();
    }
}
