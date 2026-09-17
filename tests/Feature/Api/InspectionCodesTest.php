<?php

namespace Tests\Feature\Api;

use App\Models\InspectionCode;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The building codes an inspection cites, and how they reach a report.
 *
 * The point of a library rather than free text is that `R1003.11` is written
 * once and corrected once. The point of freezing it into the VERSION is that
 * correcting it tomorrow does not rewrite what a report published today said
 * the violation was.
 */
class InspectionCodesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_library_is_listed_created_edited_and_removed(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])->postJson('/api/v1/inspections/codes', [
            'code' => 'R1003.11',
            'title' => 'R1003.11 FLUE LINING (MATERIAL)',
            'body' => 'All chimneys shall be lined.',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/inspections/codes')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'R1003.11');

        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/codes/{$id}", ['body' => 'Corrected wording.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Corrected wording.');

        $this->signedInAs($tenant['admin'])
            ->deleteJson("/api/v1/inspections/codes/{$id}")
            ->assertOk();

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/inspections/codes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_code_needs_a_number_a_title_and_a_body(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections/codes', ['code' => 'R1003.11'])
            ->assertStatus(422);
    }

    public function test_another_organizations_library_is_out_of_reach(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $theirs = $this->inTenant($beta['org'], fn () => InspectionCode::create([
            'code' => 'R1003.11', 'title' => 'Theirs', 'body' => 'Theirs.',
        ]));

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/inspections/codes/{$theirs->id}")
            ->assertNotFound();
    }

    /**
     * A role without `inspection.edit` may read the library and not change it.
     *
     * A technician has to SEE the code they are being held to — it is printed
     * on the report they hand a customer — and has no business editing it.
     */
    public function test_reading_needs_view_and_writing_needs_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($tenant, 'Rodrigo', ['inspection.view', 'inspection.create', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);

        $this->inTenant($tenant['org'], fn () => InspectionCode::create([
            'code' => 'R1003.11', 'title' => 'Flue lining', 'body' => 'All chimneys shall be lined.',
        ]));

        $this->signedInAs($tech)->getJson('/api/v1/inspections/codes')->assertOk();

        $this->signedInAs($tech)->postJson('/api/v1/inspections/codes', [
            'code' => 'X', 'title' => 'X', 'body' => 'X',
        ])->assertForbidden();
    }

    // ── the freeze ───────────────────────────────────────────────

    /**
     * Publishing writes the wording INTO the version, as text.
     *
     * The draft carries `code_id`, which is a pointer. The version carries the
     * words, because a version is written once and a report pins one.
     */
    public function test_publishing_freezes_the_citation_into_the_version(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $code = $this->inTenant($tenant['org'], fn () => InspectionCode::create([
            'code' => 'R1003.11',
            'title' => 'R1003.11 FLUE LINING (MATERIAL)',
            'body' => 'All chimneys shall be lined.',
        ]));

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])->assertCreated()->json('data.id');

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/inspections/{$id}", [
            'name' => 'Level 1',
            'screens' => [[
                'name' => 'Liner',
                'fields' => [[
                    'key' => 'liner', 'label' => 'Chimney liner', 'type' => 'text',
                    'code_id' => $code->id,
                ]],
            ]],
        ])->assertOk();

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/inspections/{$id}/publish")
            ->assertOk();

        $version = $this->inTenant($tenant['org'], fn () => InspectionDefinitionVersion::query()
            ->where('inspection_definition_id', $id)->firstOrFail());

        $frozen = $version->screens[0]['fields'][0]['code'];

        $this->assertSame('R1003.11', $frozen['code']);
        $this->assertSame('R1003.11 FLUE LINING (MATERIAL)', $frozen['title']);
        $this->assertSame('All chimneys shall be lined.', $frozen['body']);
    }

    /**
     * Correcting the library does not rewrite a published version.
     *
     * The whole reason the words are copied rather than pointed at. A report
     * says what the violation was ON THE DAY, and a typo fixed in September
     * must not change what an August report told a customer.
     */
    public function test_correcting_a_code_leaves_a_published_version_alone(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $code = $this->inTenant($tenant['org'], fn () => InspectionCode::create([
            'code' => 'R1003.11', 'title' => 'Flue lining', 'body' => 'Original wording.',
        ]));

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])->assertCreated()->json('data.id');

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/inspections/{$id}", [
            'name' => 'Level 1',
            'screens' => [[
                'fields' => [[
                    'key' => 'liner', 'label' => 'Liner', 'type' => 'text', 'code_id' => $code->id,
                ]],
            ]],
        ])->assertOk();

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/inspections/{$id}/publish")->assertOk();

        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/codes/{$code->id}", ['body' => 'Rewritten in 2027.'])
            ->assertOk();

        $version = $this->inTenant($tenant['org'], fn () => InspectionDefinitionVersion::query()
            ->where('inspection_definition_id', $id)->firstOrFail());

        $this->assertSame(
            'Original wording.',
            $version->screens[0]['fields'][0]['code']['body'],
            'a version is written once, and the citation is part of what was written',
        );
    }

    /**
     * A citation pointing at a code that has been deleted publishes without one.
     *
     * Refusing to publish would let removing one library row block every form
     * that ever mentioned it. The field keeps its question and loses its
     * reference, which is the smaller loss and the visible one.
     */
    public function test_publishing_survives_a_code_that_was_deleted(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $code = $this->inTenant($tenant['org'], fn () => InspectionCode::create([
            'code' => 'R1003.11', 'title' => 'Flue lining', 'body' => 'Wording.',
        ]));

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])->assertCreated()->json('data.id');

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/inspections/{$id}", [
            'name' => 'Level 1',
            'screens' => [[
                'fields' => [[
                    'key' => 'liner', 'label' => 'Liner', 'type' => 'text', 'code_id' => $code->id,
                ]],
            ]],
        ])->assertOk();

        $this->signedInAs($tenant['admin'])
            ->deleteJson("/api/v1/inspections/codes/{$code->id}")->assertOk();

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/inspections/{$id}/publish")
            ->assertOk();

        $version = $this->inTenant($tenant['org'], fn () => InspectionDefinitionVersion::query()
            ->where('inspection_definition_id', $id)->firstOrFail());

        $this->assertArrayNotHasKey('code', $version->screens[0]['fields'][0]);
    }

    /** A code from another organization cannot be cited. */
    public function test_a_code_from_another_organization_cannot_be_cited(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $theirs = $this->inTenant($beta['org'], fn () => InspectionCode::create([
            'code' => 'R1003.11', 'title' => 'Theirs', 'body' => 'Theirs.',
        ]));

        $id = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/inspections', [])->assertCreated()->json('data.id');

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/inspections/{$id}", [
            'screens' => [[
                'fields' => [[
                    'key' => 'liner', 'label' => 'Liner', 'type' => 'text',
                    'code_id' => $theirs->id,
                ]],
            ]],
        ])->assertStatus(422);
    }
}
