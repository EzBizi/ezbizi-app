<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which roles a role may offer when inviting, set on the role (Jose,
 * 2026-10-01, on Eve's Suggestion: "Lead manager should have access to all
 * roles to invite" and "doable on roles and permissions").
 */
class RoleInvitableRolesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_role_with_a_list_offers_exactly_that_list(): void
    {
        $t = $this->provisionTenant('Alpha');
        $lead = $this->userWithRole($t, 'Lead Manager', ['hr.view', 'hr.invitations.view', 'hr.create']);
        $tech = $this->userWithRole($t, 'Technician', ['customers.view', 'jobs.view']);
        $clerk = $this->userWithRole($t, 'Clerk', ['hr.view']);

        $invite = fn (string $roleId, string $email) => $this->signedInAs($lead)->postJson('/api/v1/hr/invitations', [
            'email' => $email, 'role_id' => $roleId, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
        ]);

        // The rule by reach: Technician reaches further than Lead Manager.
        $invite($tech->role_id, 'one@example.test')->assertStatus(422)->assertJsonValidationErrors('role_id');

        $this->signedInAs($t['admin'])->patchJson("/api/v1/roles/{$lead->role_id}", [
            'invitable_roles' => [$tech->role_id],
        ])->assertOk()->assertJsonPath('data.invitable_roles', [$tech->role_id]);

        $invite($tech->role_id, 'two@example.test')->assertCreated();
        // Clerk is within reach, and not on the list: the list is the rule now.
        $invite($clerk->role_id, 'three@example.test')->assertStatus(422);

        $options = collect($this->signedInAs($lead)->getJson('/api/v1/hr/people/scope-options')->json('data.roles'))
            ->keyBy('id');
        $this->assertTrue($options[$tech->role_id]['assignable']);
        $this->assertFalse($options[$clerk->role_id]['assignable']);

        // Back to the rule by reach.
        $this->signedInAs($t['admin'])->patchJson("/api/v1/roles/{$lead->role_id}", ['invitable_roles' => null])->assertOk();
        $invite($tech->role_id, 'four@example.test')->assertStatus(422);
    }

    public function test_an_editor_cannot_list_a_role_reaching_further_than_their_own(): void
    {
        $t = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($t, 'Office', ['roles.view', 'roles.edit', 'hr.view']);
        $tech = $this->userWithRole($t, 'Technician', ['customers.view', 'jobs.view']);

        $this->signedInAs($editor)->patchJson("/api/v1/roles/{$editor->role_id}", [
            'invitable_roles' => [$tech->role_id],
        ])->assertStatus(422)->assertJsonValidationErrors('invitable_roles');
    }
}
