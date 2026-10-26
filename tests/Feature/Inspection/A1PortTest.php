<?php

namespace Tests\Feature\Inspection;

use App\Models\InspectionCode;
use App\Models\InspectionDefinition;
use App\Support\Inspection\Condition;
use App\Support\Inspection\OptionSet;
use App\Support\Inspection\RequiredFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A1's inspection, expressed entirely as data.
 *
 * ── This is the engine's acceptance test ─────────────────────────
 *
 * Phases 1 to 8 each proved one mechanism in isolation. This proves the thing
 * they were built for: that the real inspection — the one that exists today as
 * 2,818 lines of TypeScript nobody can edit — goes in through `FieldSet`, the
 * Builder's own validator, and comes out as a definition A1 can change.
 *
 * If any part of it needed a special case, this is where that would surface.
 */
class A1PortTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_inspection_imports_as_an_editable_draft(): void
    {
        $tenant = $this->provisionTenant('A1 Group');

        $this->artisan('inspection:import-a1', ['organization' => 'A1 Group'])
            ->assertSuccessful();

        $definition = $this->inTenant(
            $tenant['org'],
            fn () => InspectionDefinition::query()->firstOrFail(),
        );

        // A draft. Publishing is A1's decision after they have read it, not a
        // side effect of an import somebody ran.
        $this->assertNull($definition->published_at);
        $this->assertCount(4, $definition->screens);

        $names = array_column($definition->screens, 'name');
        $this->assertSame(['Client and site', 'The system', 'Exterior', 'Sign off'], $names);
    }

    /** The codes are written once and cited, not pasted per field. */
    public function test_the_code_library_is_populated_and_cited(): void
    {
        $tenant = $this->provisionTenant('A1 Group');

        $this->artisan('inspection:import-a1', ['organization' => 'A1 Group'])->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $codes = InspectionCode::query()->pluck('code')->all();

            $this->assertContains('R1003.11', $codes);
            $this->assertContains('NFPA 211', $codes);

            // Cited from more than one field — the whole reason it is a library.
            $definition = InspectionDefinition::query()->firstOrFail();
            $liner = InspectionCode::query()->where('code', 'R1003.11')->firstOrFail();

            $citing = 0;
            foreach ($definition->screens as $screen) {
                foreach ($screen['fields'] as $field) {
                    if (($field['code_id'] ?? null) === $liner->getKey()) {
                        $citing++;
                    }
                }
            }

            $this->assertGreaterThan(1, $citing,
                'R1003.11 is cited by every system type that has a liner');
        });
    }

    /** Re-importing does not create a second copy of every code. */
    public function test_importing_twice_reuses_the_codes(): void
    {
        $tenant = $this->provisionTenant('A1 Group');

        $this->artisan('inspection:import-a1', ['organization' => 'A1 Group'])->assertSuccessful();
        $this->artisan('inspection:import-a1', [
            'organization' => 'A1 Group', '--name' => 'Second copy',
        ])->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(
                InspectionCode::query()->count(),
                InspectionCode::query()->distinct()->count('code'),
                'a re-import must cite the existing library rather than duplicating it',
            );
        });
    }

    /** The same name twice is refused rather than overwriting A1's edits. */
    public function test_a_second_import_under_the_same_name_is_refused(): void
    {
        $this->provisionTenant('A1 Group');

        $this->artisan('inspection:import-a1', ['organization' => 'A1 Group'])->assertSuccessful();
        $this->artisan('inspection:import-a1', ['organization' => 'A1 Group'])->assertFailed();
    }

    /**
     * THE PORTED DRAFT SURVIVES PUBLISH.
     *
     * The import deliberately creates a draft and never publishes, so that
     * somebody reads it in the Builder first. That leaves an untested step
     * between "imported" and "usable": publish re-runs the whole definition
     * through `FieldSet`, drops citations whose codes have gone, and freezes
     * the rest into a version. 166 fields carrying code citations is exactly
     * the shape that could pass the import's validation and fail the
     * publisher's — and nobody would find out until they clicked the button
     * and got an error on a form they had been told was ready.
     *
     * Asserted through the real endpoint rather than by calling the model, so
     * the authorisation, the citation freeze and the version write are all the
     * ones production uses.
     */
    public function test_the_ported_inspection_can_actually_be_published(): void
    {
        $tenant = $this->provisionTenant('A1 Group');

        $this->artisan('inspection:import-a1', ['organization' => 'A1 Group'])->assertSuccessful();

        $definition = $this->inTenant(
            $tenant['org'],
            fn () => InspectionDefinition::query()->firstOrFail(),
        );

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/inspections/{$definition->id}/publish")
            ->assertOk();

        [$publishedAt, $versionId, $screenCount] = $this->inTenant($tenant['org'], function () use ($definition) {
            $fresh = $definition->fresh()->load('currentVersion');

            return [
                $fresh->published_at,
                $fresh->current_version_id,
                count($fresh->currentVersion?->screens ?? []),
            ];
        });

        $this->assertNotNull($publishedAt, 'the ported inspection must be publishable');
        $this->assertNotNull($versionId, 'publishing must leave a frozen version to run from');
        $this->assertSame(4, $screenCount, 'the frozen version must carry all four screens');
    }

    public function test_it_can_belong_to_one_company(): void
    {
        $tenant = $this->provisionTenant('A1 Group');
        $companyName = $this->inTenant($tenant['org'], fn () => $tenant['company']->name);

        $this->artisan('inspection:import-a1', [
            'organization' => 'A1 Group', '--company' => $companyName,
        ])->assertSuccessful();

        $definition = $this->inTenant(
            $tenant['org'],
            fn () => InspectionDefinition::query()->firstOrFail(),
        );

        $this->assertSame('company', $definition->scope_type);
        $this->assertSame($tenant['company']->id, $definition->scope_id);
    }

    // ── the behaviour that makes it an inspection rather than a list ──

    /**
     * A masonry inspection does not ask about wood stoves.
     *
     * The branch is the reason the engine exists. Answering one question on the
     * system screen removes dozens of others across the form.
     */
    public function test_choosing_a_system_hides_the_other_branches(): void
    {
        $screens = $this->importedScreens();
        $system = $screens[1];

        $masonry = $this->shownKeys($system, ['system_type' => 'masonry']);
        $wood = $this->shownKeys($system, ['system_type' => 'wood']);

        $this->assertContains('masonry_firebox', $masonry);
        $this->assertNotContains('wood_stove', $masonry);

        $this->assertContains('wood_stove', $wood);
        $this->assertNotContains('masonry_firebox', $wood);
    }

    /**
     * A Level 1 is not asked the exterior questions at all.
     *
     * A whole screen behind one condition — which the old tool needed a bespoke
     * "invisible state" component to fake.
     */
    public function test_a_level_one_skips_the_exterior_screen(): void
    {
        $exterior = $this->importedScreens()[2];

        $this->assertFalse(Condition::passes($exterior['visible_if'], ['inspection_level' => '1']));
        $this->assertTrue(Condition::passes($exterior['visible_if'], ['inspection_level' => '2']));
    }

    /**
     * Failing a finding demands a photograph, and passing does not.
     *
     * The single most valuable idea in the tool being ported, arriving intact
     * through the import.
     */
    public function test_a_failed_finding_demands_its_photograph(): void
    {
        $screens = $this->importedScreens();
        $exterior = [$screens[2]];

        // Every other finding on the screen passes, so the assertion is about
        // the crown and not about whichever question happens to come first.
        $allPassing = ['inspection_level' => '2'];
        foreach ($screens[2]['fields'] as $field) {
            if (($field['type'] ?? '') === 'choice' && ($field['required'] ?? false) === true) {
                $allPassing[$field['key']] = 'ok';
            }
        }

        $this->assertNull(
            RequiredFields::firstMissing($exterior, $allPassing),
            'a screen of passing findings asks for nothing further',
        );

        $failing = [...$allPassing, 'chimney_crown_condition' => 'not-ok'];

        $this->assertSame(
            'Photograph',
            RequiredFields::firstMissing($exterior, $failing),
            'a failing crown cannot be recorded without one',
        );
    }

    /** A failing finding offers priced work, which becomes the quote. */
    public function test_a_failed_finding_offers_recommended_work(): void
    {
        $screens = $this->importedScreens();
        $crown = null;

        foreach ($screens[2]['fields'] as $field) {
            if (($field['key'] ?? '') === 'chimney_crown_condition') {
                $crown = $field;
            }
        }

        $this->assertNotNull($crown);

        $revealed = OptionSet::revealedBy($crown, ['chimney_crown_condition' => 'not-ok']);
        $types = array_column($revealed, 'type');

        $this->assertContains('photo', $types);
        $this->assertContains('services', $types);
    }

    /**
     * `system_type` steers everything and IS printed: A1's own report shows
     * "System Type: Pre-Fabricated Fireplace" under System Details (140 of its
     * October reports read, 2026-10-26), so EzBizi's does too.
     */
    public function test_the_system_type_is_printed_as_a1_prints_it(): void
    {
        foreach ($this->importedScreens()[1]['fields'] as $field) {
            if (($field['key'] ?? '') === 'system_type') {
                $this->assertSame('text', $field['print']);

                return;
            }
        }

        $this->fail('system_type should be on the system screen');
    }

    /** @return list<array<string, mixed>> */
    private function importedScreens(): array
    {
        $tenant = $this->provisionTenant('A1 Group');

        $this->artisan('inspection:import-a1', ['organization' => 'A1 Group'])->assertSuccessful();

        return $this->inTenant(
            $tenant['org'],
            fn () => InspectionDefinition::query()->firstOrFail()->screens,
        );
    }

    /**
     * The keys actually asked on one screen, given these answers.
     *
     * @param  array<string, mixed>  $screen
     * @return list<string>
     */
    private function shownKeys(array $screen, array $answers): array
    {
        $keys = [];

        foreach ($screen['fields'] as $field) {
            if (! Condition::passes($field['visible_if'] ?? null, $answers)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
