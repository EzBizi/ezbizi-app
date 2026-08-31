<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Deleting your own account.
 *
 * The cases that matter are the refusals. Anybody can write the happy path; what
 * decides whether this is safe is that it cannot be aimed at somebody else, that
 * a stolen session alone is not enough, and that it will not leave an
 * organization with nobody able to run it.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsTenants;

    public function test_a_person_can_delete_their_own_account(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $member = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        $email = $member->email;

        $this->signedInAs($member)
            ->deleteJson('/api/v1/me', ['password' => 'a-real-password', 'confirm' => 'DELETE'])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        // Anonymised in place, not deleted as a row — the Anonymiser keeps the
        // record so the jobs it is attached to do not lose their history.
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_the_password_is_required_and_checked(): void
    {
        $tenant = $this->provisionTenant('Bravo');
        $member = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        $this->signedInAs($member)
            ->deleteJson('/api/v1/me', ['password' => 'not-the-password', 'confirm' => 'DELETE'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');

        // Still there. A wrong password must not be a partial delete.
        $this->assertDatabaseHas('users', ['id' => $member->id, 'email' => $member->email]);
    }

    public function test_the_confirmation_word_must_be_typed(): void
    {
        $tenant = $this->provisionTenant('Charlie');
        $member = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        // A session and the right password are not enough on their own. This is
        // the guard against a stray tap on a phone.
        $this->signedInAs($member)
            ->deleteJson('/api/v1/me', ['password' => 'a-real-password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    /**
     * The collision between Apple's rule and §4's.
     *
     * Apple requires a person to be able to delete their account; §4 requires an
     * organization never to be left with nobody able to administer it. A sole
     * administrator is where those disagree, and §4 wins — but the refusal has
     * to say what to do about it, because a dead end is what Apple actually
     * objects to.
     */
    public function test_a_sole_administrator_is_refused_with_a_way_out(): void
    {
        $tenant = $this->provisionTenant('Delta');
        $admin  = $tenant['admin'];

        $response = $this->signedInAs($admin)
            ->deleteJson('/api/v1/me', ['password' => 'a-real-password', 'confirm' => 'DELETE'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_administrator');

        $message = $response->json('message');

        // Not just "no". The sentence has to carry both routes out, or the
        // person is stuck and the refusal is the dead end Apple rejects.
        $this->assertStringContainsString('only administrator', $message);
        $this->assertStringContainsString('administrator first', $message);
        $this->assertStringContainsString('contact@ezbizi.com', $message);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
    }

    public function test_an_administrator_may_delete_once_another_exists(): void
    {
        $tenant = $this->provisionTenant('Echo');
        $admin  = $tenant['admin'];

        // A second administrator, so the floor is no longer this person.
        $this->inTenant($tenant['org'], function () use ($tenant, $admin) {
            User::create([
                'organization_id' => $tenant['org']->id,
                'role_id'         => $admin->role_id,
                'name'            => 'Second Admin',
                'email'           => 'second@delta.test',
                'password'        => 'a-real-password',
                'is_active'       => true,
            ]);
        });

        $this->signedInAs($admin->fresh())
            ->deleteJson('/api/v1/me', ['password' => 'a-real-password', 'confirm' => 'DELETE'])
            ->assertOk();
    }

    public function test_the_preview_says_whether_it_may_happen_before_anybody_types_a_password(): void
    {
        $tenant = $this->provisionTenant('Foxtrot');

        // The sole administrator is told up front, so the client can carry the
        // sentence rather than discovering the refusal on submit.
        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/me/deletion')
            ->assertOk()
            ->assertJsonPath('data.may_delete', false)
            ->assertJsonStructure(['data' => ['may_delete', 'blocked_because', 'preview']]);

        $member = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        $this->signedInAs($member)
            ->getJson('/api/v1/me/deletion')
            ->assertOk()
            ->assertJsonPath('data.may_delete', true)
            ->assertJsonPath('data.blocked_because', null);
    }

    public function test_deleting_revokes_every_session_not_just_this_one(): void
    {
        $tenant = $this->provisionTenant('Golf');
        $member = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        // A second token, as if they were signed in on a phone as well.
        $member->createToken('phone');
        $this->assertGreaterThan(0, $member->tokens()->count());

        $this->signedInAs($member)
            ->deleteJson('/api/v1/me', ['password' => 'a-real-password', 'confirm' => 'DELETE'])
            ->assertOk();

        // A token outliving the account authenticates somebody who is gone.
        $this->assertSame(0, $member->tokens()->count());
    }

    public function test_it_is_audited_under_the_person_who_asked(): void
    {
        $tenant = $this->provisionTenant('Hotel');
        $member = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        $this->signedInAs($member)
            ->deleteJson('/api/v1/me', ['password' => 'a-real-password', 'confirm' => 'DELETE'])
            ->assertOk();

        // The audit row is the only thing that outlives the account. Without it
        // an account vanishes with nothing saying it was ever asked for.
        $this->assertDatabaseHas('audit_events', ['action' => 'user.self_deleted']);
    }
}
