<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Automation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What a 403 says (R8).
 *
 * Five policies used to return a bare `false`, so every refusal they made came
 * back as Laravel's "This action is unauthorized." — which names no permission,
 * no place and no remedy. §4 refuses for two entirely different reasons with two
 * entirely different fixes, and the sentence has to say which one happened:
 *
 *   - the ROLE is missing something, which somebody who edits roles can add
 *   - the ASSIGNMENT is missing something, which is a different screen and
 *     often a different person
 *
 * Telling somebody who has the permission and no assignment that they "do not
 * have permission" sends them to ask for a grant they already hold. That is the
 * specific failure these assertions exist to prevent, so they are written
 * against the DISTINCTION rather than against the wording: each one checks that
 * the sentence names the gate that actually failed and does not name the other.
 */
class RefusalsExplainThemselvesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the role is what is missing ──────────────────────────────

    public function test_a_missing_permission_says_it_lives_on_a_role(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);

        $message = $this->signedInAs($viewer)->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name'                => 'Nope',
        ])->assertForbidden()->json('message');

        $this->assertStringContainsString('add a customer', $message);
        $this->assertStringContainsString('role', $message);

        $this->assertStringNotContainsString(
            'This action is unauthorized',
            $message,
            'The framework default is what this whole change exists to remove.',
        );
    }

    public function test_switching_an_automation_off_without_the_permission_says_why(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $reader = $this->userWithRole($alpha, 'Reader', ['automations.view']);

        $automation = $this->inTenant($alpha['org'], fn () => Automation::create([
            'organization_id' => $alpha['org']->id,
            'name'            => 'Chase completions',
            'trigger_key'     => 'job.status_changed',
            'is_active'       => true,
        ]));

        // Reading the rule is allowed, and changing it is not — which is exactly
        // the state that reads as a broken switch unless the refusal says so.
        $this->signedInAs($reader)
            ->getJson("/api/v1/automations/{$automation->getKey()}")
            ->assertOk();

        $message = $this->signedInAs($reader)
            ->patchJson("/api/v1/automations/{$automation->getKey()}", ['is_active' => false])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('change an automation', $message);
        $this->assertStringContainsString('role', $message);
    }

    // ── the assignment is what is missing ────────────────────────

    public function test_holding_the_permission_with_no_assignment_names_the_assignment(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $customer = $this->customerIn($alpha);
        $stranded = $this->userWithNoAssignment($alpha, 'Stranded', ['jobs.view', 'jobs.create']);

        $message = $this->signedInAs($stranded)->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $customer,
            'title'        => 'Nowhere to put it',
            'status'       => 'scheduled',
        ])->assertForbidden()->json('message');

        // The remedy is an assignment, and saying "you do not have permission"
        // to somebody who holds it is how they end up asking for the wrong fix.
        $this->assertStringContainsString('not assigned', $message);
        $this->assertStringContainsString('administrator can assign you', $message);
        $this->assertStringNotContainsString('do not have permission', $message);
    }

    public function test_the_organizations_own_word_for_a_franchise_is_used(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // §8: an organization renames it, and a refusal is text on a screen like
        // any other. This is the one sentence most likely to be left saying
        // "franchise" to a business that has never used the word.
        $alpha['org']->forceFill(['franchise_label' => 'Territory'])->save();

        $customer = $this->customerIn($alpha);
        $stranded = $this->userWithNoAssignment($alpha, 'Stranded', ['jobs.view', 'jobs.create']);

        $message = $this->signedInAs($stranded)->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $customer,
            'title'        => 'Nowhere to put it',
            'status'       => 'scheduled',
        ])->assertForbidden()->json('message');

        $this->assertStringContainsString('territory', $message);
        $this->assertStringNotContainsString('franchise', $message);
    }

    public function test_a_place_outside_the_assignment_is_named(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Rhode Island',
        ]));

        $local = $this->userWithRole(
            $alpha, 'Local', ['customers.view', 'customers.create'], onlyAt: $alpha['franchise'],
        );

        $message = $this->signedInAs($local)->postJson('/api/v1/customers', [
            'owning_franchise_id' => $elsewhere->getKey(),
            'name'                => 'Somebody else’s',
        ])->assertForbidden()->json('message');

        // Named, because "outside your reach" without saying which place leaves
        // somebody with four franchises no better off than a blank 403.
        $this->assertStringContainsString('Rhode Island', $message);
        $this->assertStringContainsString('outside where you are assigned', $message);
    }

    // ── the position is what is missing ──────────────────────────

    public function test_adding_a_company_from_below_says_authority_does_not_flow_upwards(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $manager = $this->userWithRole(
            $alpha,
            'Branch Manager',
            ['companies.view', 'companies.create'],
            onlyAt: $alpha['franchise'],
        );

        $message = $this->signedInAs($manager)->postJson('/api/v1/companies', [
            'name'      => 'A whole new branch',
            'franchise' => ['name' => 'Its first place'],
        ])->assertForbidden()->json('message');

        // The one refusal in the system that is about POSITION rather than
        // place, and therefore the one where "ask for the permission" is the
        // wrong advice — they already hold it.
        $this->assertStringContainsString('whole organization', $message);
        $this->assertStringContainsString('does not flow upwards', $message);
    }

    // ── the organization tree answers each act separately ────────

    /**
     * "Add a place" was drawn from `companies.edit`, which is not the permission
     * it needs.
     *
     * Two people were wrong about in opposite directions: somebody holding
     * `franchises.create` and not `companies.edit` had the control taken away
     * with nothing said, and somebody holding `companies.edit` and not
     * `franchises.create` was offered a control that answered 403 when pressed.
     */
    public function test_adding_a_place_asks_about_franchises_not_about_editing_the_company(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $placer = $this->userWithRole($alpha, 'Placer', [
            'companies.view', 'franchises.create',
        ]);

        $company = $this->signedInAs($placer)->getJson('/api/v1/companies')
            ->assertOk()->json('companies.0');

        $this->assertFalse($company['may_edit'], 'They cannot rename the company.');
        $this->assertTrue($company['may_add_franchise'], 'But they can add a place to it.');
        $this->assertNull($company['may_add_franchise_reason']);

        $renamer = $this->userWithRole($alpha, 'Renamer', [
            'companies.view', 'companies.edit',
        ]);

        $company = $this->signedInAs($renamer)->getJson('/api/v1/companies')
            ->assertOk()->json('companies.0');

        $this->assertTrue($company['may_edit']);
        $this->assertFalse(
            $company['may_add_franchise'],
            'Editing a company has never been permission to add places to it.',
        );
    }

    public function test_a_refused_control_carries_the_policys_own_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['companies.view', 'companies.create'],
            onlyAt: $alpha['franchise'],
        );

        $tree = $this->signedInAs($manager)->getJson('/api/v1/companies')->assertOk();

        // The same words the 403 would have used, so the disabled button and the
        // failed request cannot come to disagree about why.
        $this->assertFalse($tree->json('organization.may_add_company'));
        $this->assertStringContainsString(
            'does not flow upwards',
            $tree->json('organization.may_add_company_reason'),
        );

        $this->assertNull(
            $this->signedInAs($alpha['admin'])->getJson('/api/v1/companies')
                ->json('organization.may_add_company_reason'),
            'Nothing to explain when it is allowed.',
        );
    }

    /** §3 fixes the depth, so a sub company is never asked whether it may hold one. */
    public function test_a_sub_company_is_not_offered_a_sub_company(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/companies', [
            'name'              => 'Under it',
            'parent_company_id' => $alpha['company']->id,
            'franchise'         => ['name' => 'Its place'],
        ])->assertSuccessful();

        $tree = $this->signedInAs($alpha['admin'])->getJson('/api/v1/companies')->assertOk();

        $this->assertTrue($tree->json('companies.0.may_add_sub_company'));
        $this->assertFalse($tree->json('companies.0.sub_companies.0.may_add_sub_company'));
        $this->assertNull($tree->json('companies.0.sub_companies.0.may_add_sub_company_reason'));
    }

    // ── a record from another organization is not confirmed ──────

    public function test_another_organizations_record_answers_not_found(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');

        $automation = $this->inTenant($bravo['org'], fn () => Automation::create([
            'organization_id' => $bravo['org']->id,
            'name'            => 'Theirs',
            'trigger_key'     => 'job.status_changed',
        ]));

        // A sentence explaining why they may not touch it would confirm that the
        // id exists, which a 403 with a helpful message is exactly the wrong
        // place to do.
        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/automations/{$automation->getKey()}")
            ->assertNotFound();
    }

    /**
     * A customer to hang a job on, made by somebody who is allowed to.
     *
     * Jobs validate before they authorize, so a payload missing a real customer
     * comes back 422 and never reaches the policy this test is about.
     *
     * @param  array{org: \App\Models\Organization, admin: User, franchise: Franchise}  $tenant
     */
    private function customerIn(array $tenant): string
    {
        return $this->signedInAs($tenant['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $tenant['franchise']->id,
            'name'                => 'Somebody',
        ])->assertCreated()->json('data.id');
    }

    /**
     * Somebody with a role and no assignment at all.
     *
     * [BuildsTenants::userWithRole] always makes one, on purpose — reach is
     * usually the accidental reason a test fails rather than the subject of it.
     * Here it IS the subject: the whole point is a person whose role is right
     * and who stands nowhere.
     *
     * @param  array{org: \App\Models\Organization}  $tenant
     * @param  string[]  $permissionKeys
     */
    private function userWithNoAssignment(array $tenant, string $name, array $permissionKeys): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $permissionKeys) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name'            => $name,
                'is_system'       => false,
            ]);

            $role->syncPermissionKeys($permissionKeys);

            return User::create([
                'organization_id' => $tenant['org']->id,
                'role_id'         => $role->id,
                'name'            => $name,
                'email'           => strtolower($name).'@'.$tenant['org']->slug.'.test',
                'password'        => 'a-real-password',
                'is_active'       => true,
            ]);
        });
    }
}
