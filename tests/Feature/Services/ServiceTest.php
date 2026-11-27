<?php

namespace Tests\Feature\Services;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\ServiceLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Service catalogue: create, unique name, PlaceInView, delete. */
class ServiceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_create_list_update_and_delete(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Chimney sweep',
                'description' => 'A standard sweep.',
                'price_minor' => 18900,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertStringStartsWith('SVC-', $created['number']);
        $this->assertSame('Chimney sweep', $created['name']);
        $this->assertSame(18900, $created['price_minor']);
        $this->assertNull($created['photo']);

        $this->signedInAs($admin)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Chimney sweep');

        $this->signedInAs($admin)
            ->patchJson('/api/v1/services/'.$created['id'], [
                'price_minor' => 21900,
            ])
            ->assertOk()
            ->assertJsonPath('data.price_minor', 21900);

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/services/'.$created['id'])
            ->assertNoContent();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, Service::query()->count());
            $this->assertTrue(
                AutomationEvent::query()->where('event_key', 'service.created')->exists(),
            );
            $this->assertTrue(
                AutomationEvent::query()->where('event_key', 'service.updated')->exists(),
            );
            $this->assertTrue(
                AutomationEvent::query()->where('event_key', 'service.deleted')->exists(),
            );
        });
    }

    public function test_name_is_unique_inside_a_place(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Cap install',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Cap install',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This place already has a service with that name.');
    }

    public function test_company_view_cannot_file_a_service_on_a_sibling_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $sibling = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);
        });

        $this->inTenant($tenant['org'], function () use ($admin, $tenant) {
            $fresh = User::find($admin->id);
            $fresh->forceFill([
                'preferences' => array_merge($fresh->preferences ?? [], [
                    'navigation.scope' => 'company:'.$tenant['company']->id,
                ]),
            ])->save();
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $sibling->id,
                'name' => 'Beta sweep',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    public function test_group_and_label_must_fit_the_place(): void
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

        $label = $this->signedInAs($admin)
            ->postJson('/api/v1/services/labels', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Popular',
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Sweep',
                'group_id' => $group['id'],
                'label_id' => $label['id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.group.name', 'Chimney')
            ->assertJsonPath('data.label.name', 'Popular');
    }

    public function test_moving_a_service_rechecks_the_group_it_keeps(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $place = ['company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id];

        $group = $this->signedInAs($admin)
            ->postJson('/api/v1/services/groups', $place + ['name' => 'Branch only'])
            ->assertCreated()->json('data');

        $service = $this->signedInAs($admin)
            ->postJson('/api/v1/services', $place + ['name' => 'Sweep', 'group_id' => $group['id']])
            ->assertCreated()->json('data');

        // Control: an edit that does not move it keeps the group.
        $this->signedInAs($admin)
            ->patchJson('/api/v1/services/'.$service['id'], ['price_minor' => 100])
            ->assertOk()->assertJsonPath('data.group.name', 'Branch only');

        // Moving it up to the company: the branch's group no longer fits.
        $this->signedInAs($admin)
            ->patchJson('/api/v1/services/'.$service['id'], ['franchise_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('group_id');
    }
}
