<?php

namespace Tests\Feature\Hr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Who answers to whom, and who owns an area or a step (Jose, 2026-11-03, design 175). */
class OwnershipTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_reports_to_is_set_on_the_person_and_refuses_a_circle(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['jobs.view']);
        $tech = $this->userWithRole($alpha, 'Technician', ['jobs.view']);
        $as = $this->signedInAs($alpha['admin']);

        $as->patchJson("/api/v1/hr/people/{$tech->id}", ['reports_to_user_id' => $manager->id])->assertOk();
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/hr/people/{$manager->id}", ['reports_to_user_id' => $tech->id])
            ->assertStatus(422)->assertJsonValidationErrors('reports_to_user_id');
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/hr/people/{$tech->id}", ['reports_to_user_id' => $tech->id])
            ->assertStatus(422);

        $people = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/hr/ownership')->assertOk()->json('data.people'));
        $this->assertSame($manager->id, $people->firstWhere('id', $tech->id)['reports_to_user_id']);
    }

    public function test_an_owner_is_set_per_area_and_step_and_cleared_with_null(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($alpha, 'Manager', ['jobs.view']);
        $put = fn (array $body) => $this->signedInAs($alpha['admin'])->putJson('/api/v1/hr/ownership', $body);

        $put(['kind' => 'area', 'key' => 'Field', 'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
            'user_id' => $owner->id])->assertOk()->assertJsonPath('data.user.id', $owner->id);
        $put(['kind' => 'process', 'key' => 'booking', 'user_id' => $owner->id])->assertOk();
        $put(['kind' => 'process', 'key' => 'nonsense', 'user_id' => $owner->id])->assertStatus(422);
        $put(['kind' => 'process', 'key' => 'booking', 'user_id' => $alpha['admin']->id])->assertOk();

        $owners = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/hr/ownership')->json('data.owners'));
        $this->assertCount(2, $owners);
        $this->assertSame($alpha['admin']->id, $owners->firstWhere('key', 'booking')['user']['id']);

        $put(['kind' => 'area', 'key' => 'Field', 'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
            'user_id' => null])->assertOk()->assertJsonPath('data', null);

        $reader = $this->userWithRole($alpha, 'Reader', ['jobs.view']);
        $this->signedInAs($reader)->putJson('/api/v1/hr/ownership', ['kind' => 'process', 'key' => 'job', 'user_id' => null])
            ->assertForbidden();
    }
}
