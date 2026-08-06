<?php

namespace Tests\Feature\Api;

use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'tokenable_id'   => $admin->id,
            'tokenable_type' => \App\Models\User::class,
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
            'user_id'  => $chosen['user_id'],
            'email'    => 'shared@example.com',
            'password' => 'same-password',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $alpha->id);
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->org('Alpha', 'a@alpha.test');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')->assertOk();

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
}
