<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Your own account (plan §2.1 category 6, §4).
 *
 * The line these defend is ownership: everything here belongs to the person and
 * nothing here belongs to the organization. The failure to watch for is one of
 * them reaching across — either a user editing something an administrator owns,
 * or an endpoint bound to "a user" rather than "the signed-in user".
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = ['org' => $r['organization'], 'admin' => $r['administrator']];
    }

    private function login(string $email = 'a@alpha.test'): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function as(?string $token = null): static
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->login()));
    }

    public function test_you_can_change_your_own_name(): void
    {
        $this->as()->patchJson('/api/v1/me', ['name' => 'Jose Rodriguez'])
            ->assertOk()->assertJsonPath('name', 'Jose Rodriguez');

        $this->assertSame(
            'Jose Rodriguez',
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)->name,
        );
    }

    public function test_a_rename_is_audited(): void
    {
        $this->as()->patchJson('/api/v1/me', ['name' => 'Jose Rodriguez'])->assertOk();

        // It is what everybody else in the organization sees against this
        // person's actions, so a silent change makes the audit trail lie (§10).
        $this->assertDatabaseHas('audit_events', ['action' => 'user.renamed']);
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->as()->patchJson('/api/v1/me', ['name' => '   '])->assertStatus(422);
    }

    public function test_you_cannot_change_your_email_here(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'name' => 'Still Me', 'email' => 'someone-else@alpha.test',
        ])->assertOk();

        // Email is the identity somebody signs in with and where a reset goes.
        // Changing it without proving control of the new mailbox turns a
        // borrowed session into a permanent takeover, so the field is not
        // validated — it is simply never read.
        $this->assertSame(
            'a@alpha.test',
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)->email,
        );
    }

    public function test_signing_out_everywhere_else_keeps_this_session(): void
    {
        $other = $this->login();
        $mine  = $this->login();

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->as($mine)->deleteJson('/api/v1/me/sessions')
            ->assertOk()->assertJsonPath('revoked', 1);

        $this->app['auth']->forgetGuards();

        // The other device is out.
        $this->withHeader('Authorization', "Bearer {$other}")
            ->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        // Being signed out of the browser you just clicked in is confusing;
        // being left signed in everywhere else is the actual risk.
        $this->as($mine)->getJson('/api/v1/me')->assertOk();
    }

    public function test_the_session_list_says_which_one_you_are(): void
    {
        $this->login();
        $mine = $this->login();

        $sessions = $this->as($mine)->getJson('/api/v1/me/sessions')
            ->assertOk()->json('sessions');

        // "Which of these am I" is the first thing anybody asks of a session
        // list, and inferring it from a timestamp gets it wrong.
        $this->assertCount(2, $sessions);
        $this->assertSame(1, count(array_filter($sessions, fn ($s) => $s['current'])));
    }

    public function test_none_of_it_works_signed_out(): void
    {
        $this->patchJson('/api/v1/me', ['name' => 'Nobody'])->assertUnauthorized();
        $this->getJson('/api/v1/me/sessions')->assertUnauthorized();
        $this->deleteJson('/api/v1/me/sessions')->assertUnauthorized();
    }

    public function test_a_lapsed_subscription_does_not_lock_you_out_of_your_own_account(): void
    {
        $token = $this->login();

        $this->alpha['org']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        \App\Support\Subscriptions\SubscriptionGuard::flush();

        // §13 lists password and MFA recovery among the things that must keep
        // working — otherwise expiry locks somebody out of the account they
        // would use to fix the billing. Renaming yourself is the same category:
        // nothing about it is a business write.
        $this->as($token)->getJson('/api/v1/me/sessions')->assertOk();
        $this->as($token)->deleteJson('/api/v1/me/sessions')->assertOk();
    }
}
