<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Settings\Lists\ListDefinition;
use App\Support\Settings\Lists\ListRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Settings › Lists (Jose, 2026-11-04). */
class BusinessListsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = new ListRegistry;
        $registry->register(new ListDefinition(
            key: 'test.aliases', label: 'Staff aliases', module: 'phone',
            columns: [['key' => 'name', 'label' => 'Name'], ['key' => 'weight', 'label' => 'Weight', 'type' => 'number']],
            defaults: [['key' => 'bar', 'value' => ['name' => 'Bar']], ['key' => 'shoval', 'value' => ['name' => 'Shoval']]],
        ));
        $this->app->instance(ListRegistry::class, $registry);
    }

    private function keys(array $tenant): array
    {
        return $this->inTenant($tenant['org'], fn () => array_column(app(BusinessLists::class)->rows('test.aliases'), 'key'));
    }

    public function test_a_list_reads_its_defaults_until_the_business_edits_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $list = collect($this->getJson('/api/v1/settings/lists')->assertOk()->json('data'))->firstWhere('key', 'test.aliases');
        $this->assertFalse($list['edited']);
        $this->assertSame(['bar', 'shoval'], array_column($list['items'], 'key'));
    }

    public function test_one_call_adds_a_row_and_keeps_the_defaults(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['key' => 'jose', 'value' => ['name' => 'Jose']])
            ->assertCreated()->assertJsonPath('data.value.name', 'Jose');

        $this->assertSame(['bar', 'shoval', 'jose'], $this->keys($tenant));
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.list_item_added')->exists());
    }

    public function test_a_row_changes_and_is_removed_by_its_key_and_an_emptied_list_stays_empty(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $this->patchJson('/api/v1/settings/lists/test.aliases/items/bar', ['value' => ['weight' => 3]])
            ->assertOk()->assertJsonPath('data.value', ['name' => 'Bar', 'weight' => 3]);

        $this->deleteJson('/api/v1/settings/lists/test.aliases/items/bar')->assertOk();
        $this->deleteJson('/api/v1/settings/lists/test.aliases/items/shoval')->assertOk();

        $this->assertSame([], $this->keys($tenant), 'Emptied by the business, so the defaults must not come back.');
    }

    public function test_a_row_posted_without_a_key_is_keyed_by_its_first_text_column(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['value' => ['name' => 'Jose']])
            ->assertCreated()->assertJsonPath('data.key', 'Jose');
        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['value' => ['name' => 'JOSE']])
            ->assertCreated()->assertJsonPath('data.key', 'JOSE (2)');
        // "Bar" is the default "bar" to the unique index.
        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['value' => ['name' => 'Bar']])
            ->assertCreated()->assertJsonPath('data.key', 'Bar (2)');
        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['value' => ['weight' => 1]])->assertStatus(422);
    }

    public function test_reset_returns_a_list_to_its_defaults(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $this->deleteJson('/api/v1/settings/lists/test.aliases/items/bar')->assertOk();
        $this->assertSame(['shoval'], $this->keys($tenant));

        $this->postJson('/api/v1/settings/lists/test.aliases/reset')->assertOk()
            ->assertJsonPath('data.0.sort', 0);
        $this->assertSame(['bar', 'shoval'], $this->keys($tenant));
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.list_reset')->exists());
    }

    public function test_outside_any_organization_a_list_reads_its_defaults(): void
    {
        $this->assertSame(['bar', 'shoval'], array_column(app(BusinessLists::class)->rows('test.aliases'), 'key'));
    }

    public function test_bad_rows_are_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->signedInAs($tenant['admin']);

        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['key' => 'x', 'value' => ['colour' => 'red']])->assertStatus(422);
        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['key' => 'x', 'value' => ['weight' => 'heavy']])->assertStatus(422);
        $this->postJson('/api/v1/settings/lists/test.aliases/items', ['key' => 'bar', 'value' => ['name' => 'Bar again']])->assertStatus(422);
        $this->postJson('/api/v1/settings/lists/nope/items', ['key' => 'x', 'value' => []])->assertNotFound();
    }

    public function test_a_list_is_one_organizations_and_writing_needs_its_permission(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/settings/lists/test.aliases/items', ['key' => 'only-alpha', 'value' => ['name' => 'A']])->assertCreated();

        $this->assertContains('only-alpha', $this->keys($alpha));
        $this->assertNotContains('only-alpha', $this->keys($beta));

        $this->signedInAs($this->userWithRole($alpha, 'Reader', ['organizations.settings.view']))
            ->postJson('/api/v1/settings/lists/test.aliases/items', ['key' => 'y', 'value' => ['name' => 'Y']])->assertForbidden();
    }
}
