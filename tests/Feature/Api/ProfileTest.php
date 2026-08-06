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

    public function test_the_display_name_is_composed_from_the_parts(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'first_name' => 'Jose', 'middle_name' => 'Luis', 'last_name' => 'Demasi',
        ])->assertOk()->assertJsonPath('name', 'Jose Luis Demasi');

        // `name` is read by the audit trail, every list screen and the API, so
        // it cannot drift from the parts it is derived from.
        $this->assertSame(
            'Jose Luis Demasi',
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)->name,
        );
    }

    public function test_a_missing_middle_name_leaves_no_double_space(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'first_name' => 'Jose', 'last_name' => 'Demasi',
        ])->assertOk()->assertJsonPath('name', 'Jose Demasi');
    }

    public function test_the_other_profile_fields_save(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'first_name' => 'Jose', 'last_name' => 'Demasi',
            'phone' => '(888) 984-4344', 'gender' => 'Prefer not to say',
            'date_of_birth' => '1990-04-17',
        ])->assertOk()
            ->assertJsonPath('phone', '(888) 984-4344')
            ->assertJsonPath('gender', 'Prefer not to say')
            ->assertJsonPath('date_of_birth', '1990-04-17');
    }

    public function test_a_gender_outside_the_common_answers_is_accepted(): void
    {
        // A closed list is wrong for somebody and then needs a deploy to fix.
        $this->as()->patchJson('/api/v1/me', ['gender' => 'Genderfluid'])
            ->assertOk()->assertJsonPath('gender', 'Genderfluid');
    }

    public function test_a_date_of_birth_in_the_future_is_refused(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'date_of_birth' => now()->addYear()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_a_profile_change_is_audited(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'first_name' => 'Jose', 'last_name' => 'Demasi',
        ])->assertOk();

        // The name is what everybody else sees against this person's actions,
        // so a silent change makes the audit trail lie (§10).
        $this->assertDatabaseHas('audit_events', ['action' => 'user.profile_updated']);
    }

    public function test_a_date_of_birth_is_never_written_to_the_audit_log(): void
    {
        $this->as()->patchJson('/api/v1/me', ['date_of_birth' => '1990-04-17'])->assertOk();

        // §9 treats it as personal data with a legal weight. The audit log is
        // append-only, so anything copied into it is beyond the reach of an
        // erasure request — which makes it the wrong place to keep this.
        $this->assertStringNotContainsString(
            '1990-04-17',
            \App\Models\AuditEvent::query()->get()->toJson(),
        );
    }

    public function test_an_empty_first_name_is_refused(): void
    {
        $this->as()->patchJson('/api/v1/me', ['first_name' => ''])->assertStatus(422);
    }

    public function test_you_cannot_change_your_email_here(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'first_name' => 'Still', 'last_name' => 'Me',
            'email' => 'someone-else@alpha.test',
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
        $this->patchJson('/api/v1/me', ['first_name' => 'Nobody'])->assertUnauthorized();
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
