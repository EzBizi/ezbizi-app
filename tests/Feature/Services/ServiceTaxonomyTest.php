<?php

namespace Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Groups and labels: create, unique name, refuse delete while in use. */
class ServiceTaxonomyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_delete_group_refused_while_a_service_names_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $group = $this->signedInAs($admin)
            ->postJson('/api/v1/services/groups', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Chimney',
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Sweep',
                'group_id' => $group['id'],
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/services/groups/'.$group['id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This group still has services. Move them first.');
    }

    public function test_empty_group_can_be_deleted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $group = $this->signedInAs($admin)
            ->postJson('/api/v1/services/groups', [
                'company_id' => $tenant['company']->id,
                'name' => 'Caps',
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/services/groups/'.$group['id'])
            ->assertNoContent();
    }

    public function test_label_name_is_unique_inside_a_place(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $this->signedInAs($admin)
            ->postJson('/api/v1/services/labels', [
                'company_id' => $tenant['company']->id,
                'name' => 'Popular',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/services/labels', [
                'company_id' => $tenant['company']->id,
                'name' => 'Popular',
            ])
            ->assertStatus(422);
    }
}
