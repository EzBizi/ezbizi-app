<?php

namespace Tests\Feature\Api;

use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Settings › Pending (Jose, 2026-10-26). */
class PendingSettingsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function keysFor(string $placeName): array
    {
        $group = collect($this->getJson('/api/v1/settings/pending')->assertOk()->json('data'))
            ->firstWhere('place.name', $placeName);

        return collect($group['items'] ?? [])->pluck('key')->all();
    }

    public function test_a_gap_is_listed_and_disappears_once_filled(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $this->assertContains('company.phone', $this->keysFor('Alpha Co'));

        $this->patchJson("/api/v1/companies/{$tenant['company']->id}", ['phone' => '555-0100'])->assertOk();

        $this->assertNotContains('company.phone', $this->keysFor('Alpha Co'));
        $this->assertContains('company.logo', $this->keysFor('Alpha Co'), 'control: other gaps stay');
    }

    /** Scheduling: a branch with no service area, then one whose area has no week. */
    public function test_a_branch_without_a_bookable_area_is_a_gap(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);
        $branch = $tenant['franchise']->name;

        $this->assertContains('scheduling.service_area', $this->keysFor($branch));
        $area = $this->inTenant($tenant['org'], fn () => \App\Models\ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Dallas']));
        $this->assertNotContains('scheduling.service_area', $this->keysFor($branch));
        $this->assertContains('scheduling.area_slots', $this->keysFor($branch));

        $this->inTenant($tenant['org'], fn () => \App\Models\ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 2]));
        $this->assertNotContains('scheduling.area_slots', $this->keysFor($branch));
    }

    public function test_an_expected_setting_with_no_value_anywhere_is_a_gap(): void
    {
        $registry = new SettingRegistry();
        $registry->register(new SettingDefinition(
            key: 'test.expected', label: 'Something expected', type: SettingDefinition::TYPE_STRING,
            default: '', allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION], expected: true,
        ));
        $registry->register(new SettingDefinition(
            key: 'test.optional', label: 'Something optional', type: SettingDefinition::TYPE_STRING,
            default: '', allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
        ));
        $this->app->instance(SettingRegistry::class, $registry);
        $this->app->forgetInstance(SettingsResolver::class);

        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $keys = $this->keysFor('Alpha');
        $this->assertContains('setting:test.expected', $keys);
        $this->assertNotContains('setting:test.optional', $keys, 'Only settings declared expected are gaps.');

        $this->patchJson("/api/v1/settings/test.expected?scope_type=organization&scope_id={$tenant['org']->id}", ['value' => 'x'])
            ->assertOk();

        $this->assertNotContains('setting:test.expected', $this->keysFor('Alpha'));
    }

    public function test_it_needs_organizations_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($tenant, 'Viewer', ['organizations.view']))
            ->getJson('/api/v1/settings/pending')->assertForbidden();
    }

    public function test_a_branch_person_is_not_shown_the_organization_or_other_companies(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->provisionTenant('Beta');

        $this->signedInAs($this->userWithRole($alpha, 'Branch', ['organizations.edit'], onlyAt: $alpha['franchise']));

        $types = collect($this->getJson('/api/v1/settings/pending')->assertOk()->json('data'))
            ->pluck('place.name')->all();

        $this->assertNotContains('Alpha', $types);
        $this->assertNotContains('Beta Co', $types);
    }

    /** A company with no connected inbox is listed until one is connected. */
    public function test_a_company_without_a_connected_inbox_is_a_gap(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $this->assertContains('email.mailbox', $this->keysFor('Alpha Co'));

        $this->inTenant($tenant['org'], fn () => \App\Models\Mailbox::create([
            'organization_id' => $tenant['org']->id, 'scope_type' => 'company', 'scope_id' => $tenant['company']->id,
            'address' => 'info@alpha.test', 'display_name' => 'Info', 'provider' => 'gmail_oauth',
        ])->forceFill(['status' => \App\Models\Mailbox::STATUS_CONNECTED])->save());

        $this->assertNotContains('email.mailbox', $this->keysFor('Alpha Co'));
    }
}
