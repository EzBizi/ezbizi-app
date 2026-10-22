<?php

namespace Tests\Feature\Training;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Role;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The place-scope rule on `/training/guides` (task-4 brief), and the
 * authorization around it.
 *
 * The scope rule is the one that has leaked three times in this codebase:
 * organization rows reach every franchise downward, a company's General
 * reaches anyone who can see that company including a franchise-only user of
 * it, and a franchise row reaches only that franchise — never a sibling. The
 * sibling case is the whole point of the list tests; `show` gets its own
 * sibling test because a correctly-scoped list sitting beside a by-id fetch
 * that route-model binding resolves happily is exactly the failure mode this
 * task warns about — nothing about a passing list test keeps `show` honest.
 *
 * `/training/guides` is the AUTHORING surface (Settings tab), gated on
 * `training.manage` throughout — see TrainingGuidePolicy's docblock. Every
 * `userWithRole(...)` below therefore grants `training.manage`, not
 * `training.view`, except where the test is specifically about the gate
 * itself.
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

        $local = $this->userWithRole($tenant, 'Local', ['training.manage'], onlyAt: $riverside);

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
        $local = $this->userWithRole($tenant, 'Local', ['training.manage'], onlyAt: $riverside);

        $titles = collect($this->signedInAs($local)
            ->getJson('/api/v1/training/guides')->json('data'))->pluck('title');

        $this->assertContains(
            'Company General',
            $titles,
            'A franchise-only user must still see their own company\'s General guide.',
        );
    }

    public function test_a_franchise_cannot_fetch_a_siblings_guide_by_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate only', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $local = $this->userWithRole($tenant, 'Local', ['training.manage'], onlyAt: $riverside);

        $this->signedInAs($local)
            ->getJson('/api/v1/training/guides/'.$guide->id)
            ->assertForbidden();
    }

    public function test_index_requires_manage_and_refuses_view_only(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // Proves the re-gate: /training/guides is the authoring surface, so a
        // learner-only grant must not reach it, and an author grant must.
        $viewOnly = $this->userWithRole($tenant, 'ViewOnly', ['training.view'], onlyAt: $tenant['franchise']);
        $manager = $this->userWithRole($tenant, 'Manager', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($viewOnly)
            ->getJson('/api/v1/training/guides')
            ->assertForbidden();

        $this->signedInAs($manager)
            ->getJson('/api/v1/training/guides')
            ->assertOk();
    }

    public function test_viewing_without_any_training_permission_is_refused_with_a_reason(): void
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

    public function test_a_franchise_only_user_can_create_in_their_own_franchise_but_not_a_sibling_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $sibling = $this->inTenant($tenant['org'], fn () => Company::create([
            'organization_id' => $tenant['org']->id,
            'name' => 'Beta Co',
            'is_active' => true,
        ]));

        $local = $this->userWithRole($tenant, 'Local', ['training.manage'], onlyAt: $riverside);

        $message = $this->signedInAs($local)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $sibling->id,
                'title' => 'Beta guide',
                'status' => TrainingGuide::DRAFT,
                'screens' => [],
            ])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('assigned', $message);

        $this->signedInAs($local)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $riverside->id,
                'title' => 'Riverside guide',
                'status' => TrainingGuide::DRAFT,
                'screens' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Riverside guide')
            ->assertJsonPath('data.franchise_id', $riverside->id);
    }

    public function test_update_that_moves_a_guide_to_an_unreachable_place_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $sibling = $this->inTenant($tenant['org'], fn () => Company::create([
            'organization_id' => $tenant['org']->id,
            'name' => 'Beta Co',
            'is_active' => true,
        ]));

        // Assigned at the COMPANY, not the organization — reaches its own
        // company but neither a sibling company nor the organization above it.
        $local = $this->userAtCompany($tenant, $tenant['company'], 'CompanyManager', ['training.manage']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => null,
            'title' => 'Own General', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        // This is the intricate branch: GuidesController::update detects the
        // place changed and re-authorizes `create` against the SUBMITTED
        // place, not the guide's original one.
        $this->signedInAs($local)
            ->patchJson('/api/v1/training/guides/'.$guide->id, [
                'company_id' => $sibling->id,
            ])
            ->assertForbidden();

        $this->signedInAs($local)
            ->patchJson('/api/v1/training/guides/'.$guide->id, [
                'company_id' => null,
            ])
            ->assertForbidden();

        $this->inTenant($tenant['org'], function () use ($guide, $tenant) {
            $row = TrainingGuide::query()->find($guide->id);
            $this->assertSame($tenant['company']->id, $row?->company_id);
        });
    }

    // ── Coherence audit finding 1: a move that would strand a test ──

    /**
     * The auditor's own executed probe: an organization guide behind an
     * organization `requires_guide: true` test. Moving the guide down to a
     * franchise used to succeed (200) and then permanently 404 the guide
     * and 403 the test for every learner outside that one franchise —
     * `assertGuideGateHasATarget`'s own docblock names a test nobody can
     * ever pass as exactly the dead end this module refuses to create. The
     * move is refused instead, naming the stranded test, and the guide's
     * row never changes.
     */
    public function test_moving_a_guide_that_would_strand_a_requires_guide_test_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $riverside = $tenant['franchise'];

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Ladder Safety Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null, 'franchise_id' => null,
            'guide_id' => $guide->id, 'requires_guide' => true,
            'title' => 'Ladder Safety Test', 'status' => TrainingTest::PUBLISHED,
            'questions' => [], 'pass_mark' => 80,
        ]));

        $response = $this->signedInAs($admin)
            ->patchJson('/api/v1/training/guides/'.$guide->id, [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $riverside->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['company_id']);

        $message = trim((string) $response->json('errors.company_id.0'));
        $this->assertNotSame('', $message);
        $this->assertStringContainsString('Ladder Safety Test', $message);
        $this->assertStringNotContainsString('§', $message);
        $this->assertStringNotContainsString('GuidesController', $message);
        $this->assertStringNotContainsString('immutable', $message);

        $this->inTenant($tenant['org'], function () use ($guide, $test) {
            $row = TrainingGuide::query()->findOrFail($guide->id);
            $this->assertNull($row->company_id);
            $this->assertNull($row->franchise_id);

            // The test's own gate is untouched — refused, not detached.
            $this->assertSame($guide->id, TrainingTest::query()->findOrFail($test->id)->guide_id);
        });
    }

    /** A test that merely references the guide (no gate) is stranded the same way — the rule is not `requires_guide`-specific. */
    public function test_moving_a_guide_that_would_strand_a_non_gating_test_is_also_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $riverside = $tenant['franchise'];

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Org Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null, 'franchise_id' => null,
            'guide_id' => $guide->id, 'requires_guide' => false,
            'title' => 'Reference Only Test', 'status' => TrainingTest::PUBLISHED,
            'questions' => [], 'pass_mark' => 80,
        ]));

        $this->signedInAs($admin)
            ->patchJson('/api/v1/training/guides/'.$guide->id, [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $riverside->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    /** A guide with no test pointing at it moves freely — the new check only ever fires when something would actually strand. */
    public function test_moving_a_guide_with_no_tests_pointing_at_it_still_succeeds(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $riverside = $tenant['franchise'];

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Untested Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->signedInAs($admin)
            ->patchJson('/api/v1/training/guides/'.$guide->id, [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $riverside->id,
            ])
            ->assertOk();
    }

    /** Broadening a guide's place can only ever help a test reach it, never strand one — proves the check is not simply "any place move is refused". */
    public function test_broadening_a_guides_place_never_strands_a_test(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $riverside = $tenant['franchise'];

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'title' => 'Riverside Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'guide_id' => $guide->id, 'requires_guide' => true,
            'title' => 'Riverside Test', 'status' => TrainingTest::PUBLISHED,
            'questions' => [], 'pass_mark' => 80,
        ]));

        $this->signedInAs($admin)
            ->patchJson('/api/v1/training/guides/'.$guide->id, [
                'company_id' => $tenant['company']->id,
                'franchise_id' => null,
            ])
            ->assertOk();
    }

    public function test_destroy_removes_a_guide_you_may_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'To delete', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/training/guides/'.$guide->id)
            ->assertNoContent();

        $this->inTenant($tenant['org'], function () use ($guide) {
            $this->assertNull(TrainingGuide::query()->find($guide->id));
        });
    }

    /**
     * A user assigned at the COMPANY level rather than a franchise — Inventory's
     * own test classes each keep a private copy of this rather than adding it to
     * the shared `BuildsTenants` (see e.g. InventoryItemsApiTest::userAtCompany);
     * `userWithRole`'s `onlyAt` only accepts a Franchise, so this is that same
     * copy, for the same reason.
     *
     * @param  array{org: \App\Models\Organization}  $tenant
     * @param  string[]  $permissionKeys
     */
    private function userAtCompany(
        array $tenant,
        Company $company,
        string $roleName,
        array $permissionKeys,
    ): User {
        return $this->inTenant($tenant['org'], function () use ($tenant, $company, $roleName, $permissionKeys) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => $roleName,
                'is_system' => false,
            ]);
            $role->syncPermissionKeys($permissionKeys);

            $user = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => $roleName,
                'email' => strtolower($roleName).'@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $company->id,
            ]);

            return $user;
        });
    }

    /** The search bar: `q` narrows by title or summary; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], function () {
            TrainingGuide::create(['title' => 'Ladder safety', 'summary' => 'Three points of contact', 'status' => TrainingGuide::PUBLISHED, 'screens' => []]);
            TrainingGuide::create(['title' => 'Booking a job', 'status' => TrainingGuide::PUBLISHED, 'screens' => []]);
        });
        $as = $this->signedInAs($tenant['admin']);

        $as->getJson('/api/v1/training/guides?q=contact')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Ladder safety');
        $as->getJson('/api/v1/training/guides?q=')->assertOk()->assertJsonCount(2, 'data');
        $as->getJson('/api/v1/training/guides')->assertOk()->assertJsonCount(2, 'data');
    }
}
