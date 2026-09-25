<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Models\WorkizTeamMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Settings › Schedule: Workiz technicians linked to EzBizi users (Jose,
 * 2026-09-25). The link is the one column of the Workiz mirror that EzBizi
 * owns; a person's save makes it a decision, not the sweep's email guess.
 */
class SchedulingTechniciansTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private ?User $owner = null;

    private function member(array $tenant, string $name, bool $active = true, ?string $userId = null, bool $auto = false): WorkizTeamMember
    {
        app(TenantContext::class)->set($tenant['org']);

        return WorkizTeamMember::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => (string) random_int(1000, 999999),
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'is_field_tech' => true,
            'is_active' => $active,
            'user_id' => $userId,
            'auto_matched' => $auto,
            'payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function owner(array $tenant): User
    {
        return $this->owner ??= $this->userWithRole($tenant, 'Owner', ['scheduling.view', 'scheduling.settings.view']);
    }

    public function test_it_lists_active_technicians_with_their_link_and_the_people_to_link_to(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->member($tenant, 'Ivan Sharkov', userId: $tenant['admin']->id, auto: true);
        $this->member($tenant, 'Harry Jenkins');
        $this->member($tenant, 'Gone Person', active: false);

        $body = $this->signedInAs($this->owner($tenant))
            ->getJson('/api/v1/scheduling/technicians')
            ->assertOk()
            ->json();

        $this->assertSame(['Harry Jenkins', 'Ivan Sharkov'], array_column($body['data'], 'name'), 'Leavers are hidden by default.');
        $this->assertNull($body['data'][0]['user']);
        $this->assertSame($tenant['admin']->id, $body['data'][1]['user']['id']);
        $this->assertTrue($body['data'][1]['auto_matched']);
        $this->assertContains($tenant['admin']->id, array_column($body['users'], 'id'));

        $all = $this->signedInAs($this->owner($tenant))
            ->getJson('/api/v1/scheduling/technicians?all=1')
            ->json('data');
        $this->assertContains('Gone Person', array_column($all, 'name'));
    }

    /** Linking is a person's decision, so the sweep's "matched by email" mark goes. */
    public function test_linking_sets_the_user_and_clears_the_email_guess(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $row = $this->member($tenant, 'Ivan Sharkov', userId: $tenant['admin']->id, auto: true);
        $other = $this->owner($tenant);

        $this->signedInAs($other)
            ->putJson("/api/v1/scheduling/technicians/{$row->id}", ['user_id' => $other->id])
            ->assertOk();

        app(TenantContext::class)->set($tenant['org']);
        $row->refresh();
        $this->assertSame($other->id, $row->user_id);
        $this->assertFalse($row->auto_matched);
        $this->assertNotNull($row->link_confirmed_at);

        $this->signedInAs($other)
            ->putJson("/api/v1/scheduling/technicians/{$row->id}", ['user_id' => null])
            ->assertOk();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertNull($row->refresh()->user_id);
    }

    /** Somebody from another organization is not a person you can link to. */
    public function test_a_user_from_another_organization_cannot_be_linked(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');
        $row = $this->member($alpha, 'Ivan Sharkov');

        $this->signedInAs($this->owner($alpha))
            ->putJson("/api/v1/scheduling/technicians/{$row->id}", ['user_id' => $beta['admin']->id])
            ->assertNotFound();

        app(TenantContext::class)->set($alpha['org']);
        $this->assertNull($row->refresh()->user_id);
    }

    public function test_the_list_needs_the_settings_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $caller = $this->userWithRole($tenant, 'Caller', ['scheduling.view', 'scheduling.availability.view']);

        $this->signedInAs($caller)->getJson('/api/v1/scheduling/technicians')->assertForbidden();
    }
}
