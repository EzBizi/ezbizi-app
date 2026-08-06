<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthenticateUser;
use App\Services\Auth\AuthenticationResult;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authentication (plan §4).
 *
 * The case that shapes the whole design: one email address may exist in several
 * organizations. Getting the ORDER right — password before organization choice —
 * is what stops the login form becoming a lookup service for who works where.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private AuthenticateUser $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = app(AuthenticateUser::class);
    }

    private function provision(string $name, string $email, string $password = 'correct-horse'): array
    {
        return app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => $email, 'password' => $password],
            companyName:   'Co',
            franchiseName: 'F1',
        );
    }

    // ── the ordinary path ────────────────────────────────────────

    public function test_valid_credentials_authenticate(): void
    {
        $this->provision('Alpha', 'jose@example.com');

        $result = ($this->auth)('jose@example.com', 'correct-horse');

        $this->assertTrue($result->succeeded());
        $this->assertSame('jose@example.com', $result->user->email);
    }

    public function test_login_sets_the_tenant_context(): void
    {
        ['organization' => $org] = $this->provision('Alpha', 'jose@example.com');

        ($this->auth)('jose@example.com', 'correct-horse');

        // Without this, the first query after login runs unscoped.
        $this->assertSame($org->id, app(TenantContext::class)->id());
    }

    public function test_wrong_password_fails(): void
    {
        $this->provision('Alpha', 'jose@example.com');

        $result = ($this->auth)('jose@example.com', 'wrong');

        $this->assertSame(AuthenticationResult::FAILED, $result->status);
        $this->assertNull($result->user);
    }

    public function test_unknown_email_fails(): void
    {
        $result = ($this->auth)('nobody@example.com', 'anything');

        $this->assertSame(AuthenticationResult::FAILED, $result->status);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->provision('Alpha', 'jose@example.com');

        app(TenantContext::class)->run($org, fn () => $admin->update(['is_active' => false]));
        app(TenantContext::class)->forget();

        $this->assertSame(
            AuthenticationResult::FAILED,
            ($this->auth)('jose@example.com', 'correct-horse')->status
        );
    }

    // ── the same address in two organizations (§4) ───────────────

    public function test_same_email_different_passwords_resolves_without_a_prompt(): void
    {
        $this->provision('Alpha', 'jose@example.com', 'alpha-password');
        ['organization' => $bravo] = $this->provision('Bravo', 'jose@example.com', 'bravo-password');

        $result = ($this->auth)('jose@example.com', 'bravo-password');

        // Only one account matches, so there is nothing to disambiguate.
        $this->assertTrue($result->succeeded());
        $this->assertSame($bravo->id, $result->user->organization_id);
    }

    public function test_same_email_same_password_offers_a_choice(): void
    {
        ['organization' => $alpha] = $this->provision('Alpha', 'jose@example.com', 'shared-pw');
        ['organization' => $bravo] = $this->provision('Bravo', 'jose@example.com', 'shared-pw');

        $result = ($this->auth)('jose@example.com', 'shared-pw');

        $this->assertTrue($result->isAmbiguous());
        $this->assertCount(2, $result->candidates);

        $orgIds = collect($result->choices())->pluck('organization_id')->sort()->values()->all();
        $this->assertSame(collect([$alpha->id, $bravo->id])->sort()->values()->all(), $orgIds);
    }

    public function test_the_choice_is_only_offered_after_the_password_verifies(): void
    {
        $this->provision('Alpha', 'jose@example.com', 'shared-pw');
        $this->provision('Bravo', 'jose@example.com', 'shared-pw');

        $result = ($this->auth)('jose@example.com', 'not-the-password');

        // THE important one. If a wrong password still returned the list, the
        // login form would tell anyone who types an address which organizations
        // that person works for.
        $this->assertSame(AuthenticationResult::FAILED, $result->status);
        $this->assertSame([], $result->candidates);
        $this->assertSame([], $result->choices());
    }

    public function test_completing_a_choice_authenticates(): void
    {
        ['organization' => $alpha] = $this->provision('Alpha', 'jose@example.com', 'shared-pw');
        $this->provision('Bravo', 'jose@example.com', 'shared-pw');

        $ambiguous = ($this->auth)('jose@example.com', 'shared-pw');
        $chosen = collect($ambiguous->choices())->firstWhere('organization_id', $alpha->id);

        $result = $this->auth->completeChoice($chosen['user_id'], 'jose@example.com', 'shared-pw');

        $this->assertTrue($result->succeeded());
        $this->assertSame($alpha->id, $result->user->organization_id);
        $this->assertSame($alpha->id, app(TenantContext::class)->id());
    }

    public function test_completing_a_choice_re_verifies_the_password(): void
    {
        $this->provision('Alpha', 'jose@example.com', 'shared-pw');
        $this->provision('Bravo', 'jose@example.com', 'shared-pw');

        $ambiguous = ($this->auth)('jose@example.com', 'shared-pw');
        $chosen = $ambiguous->choices()[0];

        // The chosen id arrives from the client. Trusting it as proof of the
        // earlier step would let someone post any user id and skip the password.
        $result = $this->auth->completeChoice($chosen['user_id'], 'jose@example.com', 'wrong');

        $this->assertSame(AuthenticationResult::FAILED, $result->status);
    }

    public function test_cannot_complete_a_choice_for_a_different_email(): void
    {
        $this->provision('Alpha', 'jose@example.com', 'shared-pw');
        ['administrator' => $other] = $this->provision('Bravo', 'someone@example.com', 'other-pw');

        // Swapping in an unrelated user id must not authenticate them.
        $result = $this->auth->completeChoice($other->id, 'jose@example.com', 'shared-pw');

        $this->assertSame(AuthenticationResult::FAILED, $result->status);
    }

    // ── lapsed trial (§13) ───────────────────────────────────────

    public function test_a_lapsed_trial_still_permits_login(): void
    {
        ['organization' => $org] = $this->provision('Alpha', 'jose@example.com');

        $org->update(['trial_ends_at' => now()->subDay()]);

        $result = ($this->auth)('jose@example.com', 'correct-horse');

        // §13: the owner may still sign in and VIEW their data. Locking people
        // out of their own records turns a billing lapse into a support incident.
        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->user->organization->isReadOnly());
    }

    // ── tenancy at the boundary ──────────────────────────────────

    public function test_lookup_finds_users_across_tenants_without_leaking_them(): void
    {
        $this->provision('Alpha', 'alpha@example.com');
        $this->provision('Bravo', 'bravo@example.com');

        // Login runs before any tenant exists, so the lookup is deliberately
        // unscoped — but it must still only ever return the matching address.
        $result = ($this->auth)('bravo@example.com', 'correct-horse');

        $this->assertTrue($result->succeeded());
        $this->assertSame('bravo@example.com', $result->user->email);
    }

    public function test_authentication_leaves_no_stale_tenant_on_failure(): void
    {
        $this->provision('Alpha', 'jose@example.com');
        app(TenantContext::class)->forget();

        ($this->auth)('jose@example.com', 'wrong');

        // A failed login must not leave a tenant set — the next request in a
        // long-lived process would inherit it.
        $this->assertFalse(app(TenantContext::class)->has());
    }
}
