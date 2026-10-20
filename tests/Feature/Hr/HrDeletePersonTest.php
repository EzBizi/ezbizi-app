<?php

namespace Tests\Feature\Hr;

use App\Models\AuditEvent;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Deleting somebody for good from HR, apart from deactivating them (Jose,
 * 2026-10-20: "I need to delete this user entirely, so i can make a new one
 * for him ... we are missing full deletion").
 */
class HrDeletePersonTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_administrator_deletes_a_person_and_their_address_is_free_again(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Technician', ['jobs.view']);
        $email = $person->email;
        $this->inTenant($alpha['org'], fn () => EmergencyContact::create([
            'user_id' => $person->id, 'name' => 'Ana Ruiz', 'relationship' => 'Sister', 'phone' => '600111222',
        ]));
        $person->createToken('phone');

        // Their name, typed back. Anything else deletes nothing.
        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/hr/people/{$person->id}", ['confirm' => 'Somebody else'])
            ->assertStatus(422);
        $this->assertDatabaseHas('users', ['email' => $email]);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/hr/people/{$person->id}", ['confirm' => $person->name])
            ->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertFalse((bool) User::withoutGlobalScopes()->findOrFail($person->id)->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $person->id]);
        // Another module's data about them, by its declared inventory.
        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => EmergencyContact::where('user_id', $person->id)->count()));

        // One row, ids only.
        $event = AuditEvent::withoutGlobalScopes()->where('action', 'person.deleted')->sole();
        $this->assertStringNotContainsString($email, json_encode($event->getAttributes()));

        // The same address can be invited again.
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => $email, 'first_name' => 'Jon', 'last_name' => 'Again', 'role_id' => $person->role_id,
            'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
        ])->assertCreated();
    }

    public function test_the_preview_lists_what_goes_before_anything_is_typed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Technician', ['jobs.view']);

        $data = $this->signedInAs($alpha['admin'])->getJson("/api/v1/hr/people/{$person->id}/deletion")
            ->assertOk()->json('data');

        $this->assertNull($data['blocked_because']);
        $this->assertNotEmpty($data['preview']);
        $this->assertDatabaseHas('users', ['id' => $person->id, 'email' => $person->email]);
    }

    public function test_without_the_permission_or_on_yourself_it_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Technician', ['jobs.view']);
        $editor = $this->userWithRole($alpha, 'HR', ['hr.view', 'hr.edit', 'hr.records.view']);

        $this->signedInAs($editor)->deleteJson("/api/v1/hr/people/{$person->id}", ['confirm' => $person->name])
            ->assertForbidden();
        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/hr/people/{$alpha['admin']->id}", ['confirm' => $alpha['admin']->name])
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $person->id, 'email' => $person->email]);
    }

    public function test_the_last_administrator_is_not_deleted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $deleter = $this->userWithRole($alpha, 'HR', ['hr.view', 'hr.delete']);
        $admin = $alpha['admin'];

        $this->signedInAs($deleter)->deleteJson("/api/v1/hr/people/{$admin->id}", ['confirm' => $admin->name])
            ->assertStatus(409)->assertJsonPath('code', 'last_administrator');
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => $admin->email]);
    }
}
