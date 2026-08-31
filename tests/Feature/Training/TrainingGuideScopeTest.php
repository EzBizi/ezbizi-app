<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The place-scope rule on `GET /training/guides` (task-4 brief).
 *
 * This is the rule that has leaked three times in this codebase: organization
 * rows reach every franchise downward, a company's General reaches anyone who
 * can see that company including a franchise-only user of it, and a franchise
 * row reaches only that franchise — never a sibling. The sibling case is the
 * whole point; the other two assertions exist so a change that widened the
 * gate would also be caught.
 */
class TrainingGuideScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_franchise_sees_organization_guides_but_never_a_siblings(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $this->inTenant($tenant['org'], function () use ($tenant, $riverside, $northgate) {
            TrainingGuide::create([
                'company_id' => null, 'franchise_id' => null,
                'title' => 'Org wide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
            TrainingGuide::create([
                'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
                'title' => 'Riverside only', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
            TrainingGuide::create([
                'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
                'title' => 'Northgate only', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
        });

        $local = $this->userWithRole($tenant, 'Local', ['training.view'], onlyAt: $riverside);

        $titles = collect($this->signedInAs($local)
            ->getJson('/api/v1/training/guides')->json('data'))->pluck('title');

        $this->assertContains('Org wide', $titles, 'An organization guide must reach a franchise.');
        $this->assertContains('Riverside only', $titles);
        $this->assertNotContains('Northgate only', $titles, 'A sibling franchise must never leak.');
    }

    public function test_a_franchise_only_user_sees_the_companys_general_guide(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $this->inTenant($tenant['org'], function () use ($tenant) {
            TrainingGuide::create([
                'company_id' => $tenant['company']->id, 'franchise_id' => null,
                'title' => 'Company General', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
        });

        // Assigned only at the franchise, not the company — the soft-reach case
        // InventoryVisibility::companyIdsFor exists for: a franchise assignment
        // does not confer canActOnCompany, but it must still see that
        // company's General.
        $local = $this->userWithRole($tenant, 'Local', ['training.view'], onlyAt: $riverside);

        $titles = collect($this->signedInAs($local)
            ->getJson('/api/v1/training/guides')->json('data'))->pluck('title');

        $this->assertContains(
            'Company General',
            $titles,
            'A franchise-only user must still see their own company\'s General guide.',
        );
    }

    public function test_viewing_without_the_permission_is_refused_with_a_reason(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            TrainingGuide::create([
                'company_id' => null, 'franchise_id' => null,
                'title' => 'Org wide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
        });

        $noAccess = $this->userWithRole($tenant, 'NoAccess', [], onlyAt: $tenant['franchise']);

        $message = $this->signedInAs($noAccess)
            ->getJson('/api/v1/training/guides')
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('permission', $message);
        // The refusal is a sentence for the person reading it, not a pointer
        // back into the code that produced it.
        $this->assertStringNotContainsString('§', $message);
        $this->assertStringNotContainsString('TrainingGuidePolicy', $message);
        $this->assertStringNotContainsString('immutable', $message);
    }
}
