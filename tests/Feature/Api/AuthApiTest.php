<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The login endpoints (plan §4).
 *
 * These deliberately issue and use REAL tokens rather than Sanctum::actingAs().
 * actingAs fakes the authenticated user and never touches personal_access_tokens
 * — which is exactly how a broken token column reached the deployed app: every
 * API test passed while real logins failed with a truncated UUID.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name, string $email, string $password = 'a-real-password'): array
    {
        return app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => $email, 'password' => $password],
            companyName: 'Co', franchiseName: 'F1',
        );
    }

    public function test_login_issues_a_usable_token(): void
    {
        $this->org('Alpha', 'a@alpha.test');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->assertJsonPath('status', 'authenticated');

        $token = $login->json('token');
        $this->assertNotEmpty($token);

        // The part actingAs() skips: the token must survive a round trip through
        // the database and authenticate a subsequent request.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'a@alpha.test');
    }

    public function test_the_token_row_stores_the_full_uuid(): void
    {
        ['administrator' => $admin] = $this->org('Alpha', 'a@alpha.test');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk();

        // A bigint column truncates a UUID silently on some configurations and
        // errors on others. Assert the stored value round-trips exactly.
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->org('Alpha', 'a@alpha.test');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'wrong',
        ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_unknown_email_is_rejected_identically(): void
    {
        // Same wording and status as a wrong password: a different message would
        // let anyone enumerate which addresses are registered.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@nowhere.test', 'password' => 'whatever',
        ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_same_email_in_two_organizations_offers_a_choice(): void
    {
        $this->org('Alpha', 'shared@example.com', 'same-password');
        $this->org('Bravo', 'shared@example.com', 'same-password');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'shared@example.com', 'password' => 'same-password',
        ])->assertOk()->assertJsonPath('status', 'choose_organization');

        $this->assertCount(2, $response->json('choices'));
    }

    public function test_the_choice_list_is_never_returned_for_a_bad_password(): void
    {
        $this->org('Alpha', 'shared@example.com', 'same-password');
        $this->org('Bravo', 'shared@example.com', 'same-password');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'shared@example.com', 'password' => 'wrong',
        ])->assertUnauthorized();

        // Otherwise the login form tells anyone who types an address which
        // organizations that person works for.
        $this->assertNull($response->json('choices'));
    }

    public function test_choosing_an_organization_issues_a_token(): void
    {
        ['organization' => $alpha] = $this->org('Alpha', 'shared@example.com', 'same-password');
        $this->org('Bravo', 'shared@example.com', 'same-password');

        $choices = $this->postJson('/api/v1/auth/login', [
            'email' => 'shared@example.com', 'password' => 'same-password',
        ])->json('choices');

        $chosen = collect($choices)->firstWhere('organization_id', $alpha->id);

        $token = $this->postJson('/api/v1/auth/choose', [
            'user_id' => $chosen['user_id'],
            'email' => 'shared@example.com',
            'password' => 'same-password',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $alpha->id);
    }

    /** The picker the first step offered must work whatever case was typed. */
    public function test_choosing_works_when_the_typed_email_case_differs(): void
    {
        ['organization' => $alpha] = $this->org('Alpha', 'shared@example.com', 'same-password');
        $this->org('Bravo', 'shared@example.com', 'same-password');

        $choices = $this->postJson('/api/v1/auth/login', [
            'email' => 'Shared@Example.com', 'password' => 'same-password',
        ])->assertJsonPath('status', 'choose_organization')->json('choices');

        $chosen = collect($choices)->firstWhere('organization_id', $alpha->id);

        $this->postJson('/api/v1/auth/choose', [
            'user_id' => $chosen['user_id'],
            'email' => 'Shared@Example.com',
            'password' => 'same-password',
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->org('Alpha', 'a@alpha.test');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Laravel's RequestGuard caches the user it resolved, and the test
        // process keeps one container across all three requests — so without
        // this the second request answers from the first request's memory and
        // returns 200 for a token that no longer exists. Real requests each get
        // a fresh container; this reproduces that. Do NOT "fix" it by relaxing
        // the assertion below: the product is fine, the harness is the problem.
        $this->app['auth']->forgetGuards();

        // The same token must now be worthless.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $this->org('Alpha', 'a@alpha.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'a@alpha.test', 'password' => 'wrong',
            ])->assertUnauthorized();
        }

        // The same-email design would otherwise let an attacker test one password
        // against several organizations per request — better value than a
        // single-tenant login, so the limit matters more here than usual.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'wrong',
        ])->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    public function test_a_wrong_authenticator_code_is_throttled(): void
    {
        $r = $this->org('Alpha', 'a@alpha.test');

        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        User::withoutGlobalScopes()->whereKey($r['administrator']->id)->update([
            'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'a@alpha.test', 'password' => 'a-real-password', 'code' => '000000',
            ])->assertOk()->assertJsonPath('status', 'second_factor_required');
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password', 'code' => '000000',
        ])->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    public function test_asking_for_the_code_the_first_time_is_not_throttled(): void
    {
        $r = $this->org('Alpha', 'a@alpha.test');

        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        User::withoutGlobalScopes()->whereKey($r['administrator']->id)->update([
            'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(),
        ]);

        // The login form posts the password, learns it needs a code, then posts
        // both. Charging the first step would lock someone out for opening the
        // form six times.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'a@alpha.test', 'password' => 'a-real-password',
            ])->assertOk()->assertJsonPath('status', 'second_factor_required');
        }
    }

    public function test_choose_shares_the_login_throttle(): void
    {
        ['administrator' => $admin] = $this->org('Alpha', 'shared@example.com', 'same-password');
        $this->org('Bravo', 'shared@example.com', 'same-password');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/choose', [
                'user_id' => $admin->id,
                'email' => 'shared@example.com',
                'password' => 'wrong',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/choose', [
            'user_id' => $admin->id,
            'email' => 'shared@example.com',
            'password' => 'wrong',
        ])->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    public function test_the_api_does_not_reflect_an_arbitrary_origin(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'nobody@nowhere.test', 'password' => 'whatever',
            ])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_the_api_allows_the_customer_host(): void
    {
        $this->withHeaders(['Origin' => 'https://app.ezbizi.com'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'nobody@nowhere.test', 'password' => 'whatever',
            ])
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.ezbizi.com');
    }

    // ── two organizations, one protected (plan §4) ───────────────

    /** @return array{0: User, 1: string} the protected account and its secret */
    private function protectedTwin(string $email, string $password = 'a-real-password'): array
    {
        $r = $this->org('Beta', $email, $password);

        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        User::withoutGlobalScopes()->whereKey($r['administrator']->id)->update([
            // encryptString, not encrypt(). The `encrypted` cast reads with
            // decryptString, which does not unserialize — encrypt() wraps a
            // SERIALIZED value, so the secret came back as `s:32:"..."` and
            // base32 decoding threw. It stayed hidden because no test had
            // ever verified a user's own code, only asked for one.
            //
            // Straight to the row, on purpose: there is no tenant here, and a
            // model save would go through BelongsToTenant's write guard. The
            // comment used to say "query builder" and stop there, which was not
            // the whole story — User::whereKey() is still an ELOQUENT builder,
            // so the tenant READ scope applies to it too. That used to pass
            // silently because the scope failed open; it now refuses, so the
            // cross-tenant read has to be stated.
            'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(),
        ]);

        return [$r['administrator']->fresh(), $secret];
    }

    public function test_an_account_behind_an_authenticator_is_not_silently_skipped(): void
    {
        $this->org('Alpha', 'both@example.test');
        $this->protectedTwin('both@example.test');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'both@example.test', 'password' => 'a-real-password',
        ])->assertOk();

        // The protected account used to be set aside, the unprotected one
        // matched alone, and one match meant straight in — so the protected
        // organization was unreachable and nothing ever asked for a code.
        $response->assertJsonPath('status', 'choose_organization');

        $names = array_column($response->json('choices'), 'organization_name');
        sort($names);

        $this->assertSame(['Alpha', 'Beta'], $names);
    }

    public function test_the_picker_says_which_account_will_want_a_code(): void
    {
        $this->org('Alpha', 'both@example.test');
        $this->protectedTwin('both@example.test');

        $choices = $this->postJson('/api/v1/auth/login', [
            'email' => 'both@example.test', 'password' => 'a-real-password',
        ])->assertOk()->json('choices');

        $flags = [];

        foreach ($choices as $c) {
            $flags[$c['organization_name']] = $c['needs_code'];
        }

        // Otherwise the client picks one, is refused, and has nowhere to type
        // the thing it was refused for.
        $this->assertFalse($flags['Alpha']);
        $this->assertTrue($flags['Beta']);
    }

    public function test_choosing_the_protected_organization_works_with_a_code(): void
    {
        $this->org('Alpha', 'both@example.test');
        [$beta, $secret] = $this->protectedTwin('both@example.test');

        $this->postJson('/api/v1/auth/choose', [
            'user_id' => $beta->id,
            'email' => 'both@example.test',
            'password' => 'a-real-password',
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_mistyped_code_is_not_reported_as_a_wrong_password(): void
    {
        $r = $this->org('Alpha', 'a@alpha.test');

        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        User::withoutGlobalScopes()->whereKey($r['administrator']->id)->update([
            // encryptString, not encrypt(). The `encrypted` cast reads with
            // decryptString, which does not unserialize — encrypt() wraps a
            // SERIALIZED value, so the secret came back as `s:32:"..."` and
            // base32 decoding threw. It stayed hidden because no test had
            // ever verified a user's own code, only asked for one.
            //
            // Straight to the row, on purpose: there is no tenant here, and a
            // model save would go through BelongsToTenant's write guard. The
            // comment used to say "query builder" and stop there, which was not
            // the whole story — User::whereKey() is still an ELOQUENT builder,
            // so the tenant READ scope applies to it too. That used to pass
            // silently because the scope failed open; it now refuses, so the
            // cross-tenant read has to be stated.
            'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password', 'code' => '000000',
        ])->assertOk();

        // It used to fall through to the generic failure, so somebody who fat
        // fingered a digit was told their password was wrong — and went off to
        // reset a password that was never the problem.
        $response->assertJsonPath('status', 'second_factor_required');
        $this->assertStringContainsString('code', strtolower($response->json('message')));
    }

    public function test_the_sign_in_screen_never_mentions_the_control_plane(): void
    {
        $r = $this->org('Alpha', 'a@alpha.test');

        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        User::withoutGlobalScopes()->whereKey($r['administrator']->id)->update([
            // encryptString, not encrypt(). The `encrypted` cast reads with
            // decryptString, which does not unserialize — encrypt() wraps a
            // SERIALIZED value, so the secret came back as `s:32:"..."` and
            // base32 decoding threw. It stayed hidden because no test had
            // ever verified a user's own code, only asked for one.
            //
            // Straight to the row, on purpose: there is no tenant here, and a
            // model save would go through BelongsToTenant's write guard. The
            // comment used to say "query builder" and stop there, which was not
            // the whole story — User::whereKey() is still an ELOQUENT builder,
            // so the tenant READ scope applies to it too. That used to pass
            // silently because the scope failed open; it now refuses, so the
            // cross-tenant read has to be stated.
            'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(),
        ]);

        $message = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('message');

        // It is the one page every customer sees. Whether a second system
        // exists is nobody else's business.
        $this->assertStringNotContainsStringIgnoringCase('control plane', $message);
    }
}
