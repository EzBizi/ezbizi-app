<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionRun;
use App\Support\Files\FileStore;
use App\Support\Inspection\InspectionAccess;
use App\Support\Inspection\ScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Builder: named inspections, a working draft, and written-once versions.
 *
 * Publish is the act that freezes a version. Editing the draft afterwards must
 * not rewrite a run that already started, or a report that already exists.
 */
class InspectionDefinitionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_publish_without_a_real_name_is_refused_with_a_sentence(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);

        $id = $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            'Untitled inspection',
            $this->signedInAs($editor)->getJson("/api/v1/inspections/{$id}")->json('data.name'),
        );

        $response = $this->signedInAs($editor)
            ->postJson("/api/v1/inspections/{$id}/publish")
            ->assertStatus(422);

        $this->assertStringContainsString(
            'name',
            strtolower($response->json('message').json_encode($response->json('errors'))),
        );
        $flat = implode(' ', array_merge(...array_values($response->json('errors') ?? [''])));
        $this->assertNotSame('', $flat);
        $this->assertStringContainsString('picker', strtolower($flat));
    }

    public function test_a_second_publish_increments_version_and_an_open_run_still_pins_v1(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);

        $id = $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($editor)->patchJson("/api/v1/inspections/{$id}", [
            'name' => 'Level 1',
            'layout_preset' => 'step_strip',
            'screens' => [[
                'name' => 'Crown',
                'fields' => [[
                    'key' => 'crown',
                    'label' => 'Crown',
                    'type' => 'text',
                    'required' => true,
                ]],
            ]],
        ])->assertOk();

        $this->signedInAs($editor)
            ->postJson("/api/v1/inspections/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.current_version', 1);

        $run = $this->inTenant($tenant['org'], function () use ($tenant, $id) {
            $definition = InspectionDefinition::query()->findOrFail($id);
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => '14 Maple',
            ]);

            return InspectionRun::create([
                'user_id' => $tenant['admin']->id,
                'customer_id' => $customer->id,
                'inspection_definition_id' => $definition->id,
                'inspection_definition_version_id' => $definition->current_version_id,
                'scope_key' => ScopeKey::for($customer->id, null),
                'answers' => [],
            ]);
        });

        $this->signedInAs($editor)->patchJson("/api/v1/inspections/{$id}", [
            'screens' => [[
                'name' => 'Crown',
                'fields' => [
                    [
                        'key' => 'crown',
                        'label' => 'Crown',
                        'type' => 'text',
                        'required' => true,
                    ],
                    [
                        'key' => 'notes',
                        'label' => 'Notes',
                        'type' => 'text',
                    ],
                ],
            ]],
        ])->assertOk();

        $this->signedInAs($editor)
            ->postJson("/api/v1/inspections/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.current_version', 2);

        $this->inTenant($tenant['org'], function () use ($run) {
            $this->assertSame(1, $run->fresh()->version->version);
            $this->assertCount(2, InspectionDefinitionVersion::query()->get());
        });
    }

    public function test_unpublish_hides_from_the_picker_and_does_not_delete_versions(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);
        $tech = $this->userWithRole($tenant, 'Tech', [
            'inspection.view', 'inspection.create',
        ]);

        $id = $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($editor)->patchJson("/api/v1/inspections/{$id}", [
            'name' => 'Level 1',
            'screens' => [['name' => 'One', 'fields' => [[
                'key' => 'ok', 'label' => 'Ok', 'type' => 'text',
            ]]]],
        ])->assertOk();

        $this->signedInAs($editor)->postJson("/api/v1/inspections/{$id}/publish")->assertOk();

        $this->inTenant($tenant['org'], fn () => InspectionDefinitionGrant::create([
            'inspection_definition_id' => $id,
            'role_id' => $tech->role_id,
        ]));

        $offered = $this->inTenant(
            $tenant['org'],
            fn () => app(InspectionAccess::class)->startable($tech)->pluck('id')->all(),
        );
        $this->assertSame([$id], $offered);

        $this->signedInAs($editor)
            ->postJson("/api/v1/inspections/{$id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.published', false);

        $this->inTenant($tenant['org'], function () use ($id, $tech) {
            $this->assertSame(
                [],
                app(InspectionAccess::class)->startable($tech)->pluck('id')->all(),
            );
            $this->assertSame(1, InspectionDefinitionVersion::query()->count());
            $this->assertNotNull(InspectionDefinition::query()->find($id)->current_version_id);
        });
    }

    public function test_a_second_create_gets_a_numbered_working_title(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);

        $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->assertJsonPath('data.name', 'Untitled inspection');

        $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->assertJsonPath('data.name', 'Untitled inspection 2');
    }

    public function test_builder_is_refused_without_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($tenant, 'Viewer', ['inspection.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/inspections')
            ->assertForbidden();
    }

    public function test_publish_copies_the_cover_through_file_store_onto_the_version(): void
    {
        Storage::fake(config('filesystems.default'));

        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);

        $id = $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($editor)->patchJson("/api/v1/inspections/{$id}", [
            'name' => 'Level 1',
            'screens' => [['name' => 'One', 'fields' => [[
                'key' => 'ok', 'label' => 'Ok', 'type' => 'text',
            ]]]],
        ])->assertOk();

        $this->signedInAs($editor)
            ->post("/api/v1/inspections/{$id}/cover", [
                'cover' => UploadedFile::fake()->createWithContent(
                    'front.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
                ),
            ])
            ->assertOk()
            ->assertJsonPath('data.has_cover', true);

        $this->signedInAs($editor)->postJson("/api/v1/inspections/{$id}/publish")->assertOk();

        $this->inTenant($tenant['org'], function () use ($id) {
            $definition = InspectionDefinition::query()->findOrFail($id);
            $version = $definition->currentVersion;
            $this->assertNotNull($version->cover_path);
            $this->assertNotSame($definition->cover_path, $version->cover_path);
            $this->assertTrue(app(FileStore::class)->exists($version->cover_path, $version->cover_disk));
        });
    }

    public function test_a_never_published_draft_can_be_deleted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);

        $id = $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($editor)
            ->deleteJson("/api/v1/inspections/{$id}")
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($id) {
            $this->assertNull(InspectionDefinition::query()->find($id));
        });
    }

    public function test_a_published_inspection_cannot_be_deleted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);

        $id = $this->signedInAs($editor)
            ->postJson('/api/v1/inspections')
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($editor)->patchJson("/api/v1/inspections/{$id}", [
            'name' => 'Level 1',
            'screens' => [['name' => 'One', 'fields' => [[
                'key' => 'ok', 'label' => 'Ok', 'type' => 'text',
            ]]]],
        ])->assertOk();

        $this->signedInAs($editor)->postJson("/api/v1/inspections/{$id}/publish")->assertOk();

        $response = $this->signedInAs($editor)
            ->deleteJson("/api/v1/inspections/{$id}")
            ->assertStatus(422);

        $this->assertStringContainsString('unpublish', strtolower($response->json('message')));

        $this->signedInAs($editor)->postJson("/api/v1/inspections/{$id}/unpublish")->assertOk();

        $this->signedInAs($editor)
            ->deleteJson("/api/v1/inspections/{$id}")
            ->assertStatus(422);

        $this->inTenant($tenant['org'], function () use ($id) {
            $this->assertNotNull(InspectionDefinition::query()->find($id));
            $this->assertSame(1, InspectionDefinitionVersion::query()->count());
        });
    }

    private function editor(array $tenant)
    {
        return $this->userWithRole($tenant, 'Designer', [
            'inspection.view', 'inspection.edit',
        ]);
    }
}
