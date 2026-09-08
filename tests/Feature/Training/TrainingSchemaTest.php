<?php

namespace Tests\Feature\Training;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\TrainingAssignment;
use App\Models\TrainingGuide;
use App\Models\TrainingTest as TrainingTestModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TrainingSchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── Coherence audit finding 3: a company/franchise delete must not silently re-parent Training rows onto the organization ──

    /**
     * `company_id IS NULL` means the ORGANIZATION (§3), and
     * `InventoryVisibility::constrain` tests `whereNull('company_id')`
     * first. A `nullOnDelete` place column would let deleting a company
     * silently re-parent every guide filed on it onto the organization and
     * publish it tenant-wide the moment the delete commits. The place
     * columns are `restrictOnDelete` instead (2026_09_09_100000): the
     * delete is blocked with a loud foreign-key violation, and the guide's
     * own place never changes.
     */
    public function test_deleting_a_company_with_a_guide_filed_on_it_is_blocked_not_silently_reparented(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $company = $this->inTenant($tenant['org'], fn () => Company::create([
            'organization_id' => $tenant['org']->id, 'name' => 'Doomed Co', 'is_active' => true,
        ]));

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $company->id, 'franchise_id' => null,
            'title' => 'Filed Here', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->expectException(QueryException::class);

        try {
            $this->inTenant($tenant['org'], fn () => $company->forceDelete());
        } finally {
            $this->inTenant($tenant['org'], function () use ($guide) {
                $this->assertNotNull(
                    TrainingGuide::query()->find($guide->id)?->company_id,
                    'A blocked delete must never leave the guide re-parented onto the organization.',
                );
            });
        }
    }

    /** The same rule for a franchise, and for a test rather than a guide. */
    public function test_deleting_a_franchise_with_a_test_filed_on_it_is_blocked_not_silently_reparented(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $franchise = $tenant['franchise'];

        $test = $this->inTenant($tenant['org'], fn () => TrainingTestModel::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $franchise->id,
            'title' => 'Filed Here', 'status' => TrainingTestModel::PUBLISHED, 'questions' => [], 'pass_mark' => 80,
        ]));

        $this->expectException(QueryException::class);

        try {
            $this->inTenant($tenant['org'], fn () => Franchise::query()->find($franchise->id)->forceDelete());
        } finally {
            $this->inTenant($tenant['org'], function () use ($test) {
                $this->assertNotNull(
                    TrainingTestModel::query()->find($test->id)?->franchise_id,
                    'A blocked delete must never leave the test re-parented onto the company.',
                );
            });
        }
    }

    /** And the third table carrying the same place pair: assignments. */
    public function test_deleting_a_company_with_an_assignment_filed_on_it_is_blocked_not_silently_reparented(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $company = $this->inTenant($tenant['org'], fn () => Company::create([
            'organization_id' => $tenant['org']->id, 'name' => 'Doomed Co', 'is_active' => true,
        ]));

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $company->id, 'franchise_id' => null,
            'title' => 'Filed Here', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $assignment = $this->inTenant($tenant['org'], fn () => TrainingAssignment::create([
            'company_id' => $company->id, 'franchise_id' => null,
            'guide_id' => $guide->id, 'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $learner->id,
        ]));

        $this->expectException(QueryException::class);

        try {
            $this->inTenant($tenant['org'], fn () => $company->forceDelete());
        } finally {
            $this->inTenant($tenant['org'], function () use ($assignment) {
                $this->assertNotNull(TrainingAssignment::query()->find($assignment->id)?->company_id);
            });
        }
    }

    public function test_a_guide_keeps_its_screens_in_the_order_they_were_written(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety',
            'status' => TrainingGuide::PUBLISHED,
            'screens' => [
                ['title' => 'Why this exists', 'body' => 'a', 'media_kind' => 'none', 'media_url' => null],
                ['title' => 'The four-to-one rule', 'body' => 'b', 'media_kind' => 'video', 'media_url' => 'https://youtu.be/x'],
            ],
        ]));

        // Element order survives MySQL. Key order deliberately not asserted.
        $screens = $guide->fresh()->screens;

        $this->assertCount(2, $screens);
        $this->assertSame('Why this exists', $screens[0]['title']);
        $this->assertSame('The four-to-one rule', $screens[1]['title']);
        $this->assertSame('video', $screens[1]['media_kind']);
    }

    public function test_the_guide_and_test_factories_are_wired_up(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$guide, $test] = $this->inTenant($tenant['org'], fn () => [
            TrainingGuide::factory()->create(['company_id' => $tenant['company']->id]),
            TrainingTestModel::factory()->create(['company_id' => $tenant['company']->id]),
        ]);

        $this->assertInstanceOf(TrainingGuide::class, $guide);
        $this->assertTrue($guide->exists);
        $this->assertInstanceOf(TrainingTestModel::class, $test);
        $this->assertTrue($test->exists);
    }
}
