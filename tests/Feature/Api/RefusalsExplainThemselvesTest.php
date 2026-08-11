<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Automation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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

    /**
     * Refusal's levels ARE Assignment's scope types, which is load-bearing.
     *
     * OnboardingPolicy hands `Onboarding::scope()['type']` — an Assignment scope
     * type — straight to Refusal::labelFor. That only works while the two
     * vocabularies are the same three strings, and if they ever drift the
     * failure is silent: labelFor's `default` arm would quietly answer with the
     * franchise's label for a company or the organization.
     */
    public function test_the_two_vocabularies_for_a_level_are_the_same_strings(): void
    {
        $this->assertSame(Assignment::SCOPE_ORGANIZATION, Refusal::AT_ORGANIZATION);
        $this->assertSame(Assignment::SCOPE_COMPANY, Refusal::AT_COMPANY);
        $this->assertSame(Assignment::SCOPE_FRANCHISE, Refusal::AT_FRANCHISE);
    }

    /**
     * The third reach state: assigned, standing above, and nothing down there.
     *
     * The two-state model said "you are not assigned to any franchise, an
     * administrator can assign you to one" to somebody who IS assigned, about a
     * franchise that does not exist for an administrator to assign them to.
     * Both halves wrong, which is precisely the shape R8 exists to stop.
     *
     * The state is built here rather than through the API because the API
     * refuses to produce it — see the test below, which is what makes that
     * claim something other than an assumption.
     */
    public function test_standing_above_an_empty_level_is_not_called_being_unassigned(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $fresh = $this->inTenant($alpha['org'], function () use ($alpha) {
            $placeless = Company::create([
                'organization_id' => $alpha['org']->id,
                'name'            => 'Just incorporated',
                'is_active'       => true,
            ]);

            $role = Role::create([
                'organization_id' => $alpha['org']->id,
                'name'            => 'Fresh',
                'is_system'       => false,
            ]);
            $role->syncPermissionKeys(['jobs.view', 'jobs.create']);

            $user = User::create([
                'organization_id' => $alpha['org']->id,
                'role_id'         => $role->id,
                'name'            => 'Fresh',
                'email'           => 'fresh@'.$alpha['org']->slug.'.test',
                'password'        => 'a-real-password',
                'is_active'       => true,
            ]);

            Assignment::create([
                'organization_id'     => $alpha['org']->id,
                'user_id'             => $user->id,
                'assigned_company_id' => $placeless->id,
            ]);

            return $user->fresh();
        });

        $message = $this->inTenant($alpha['org'], fn () => Gate::forUser($fresh)
            ->inspect('create', Job::class)
            ->message());

        $this->assertStringNotContainsString(
            'not assigned to any',
            $message,
            'They hold a company assignment. Telling them otherwise is false.',
        );
        $this->assertStringNotContainsString(
            'administrator can assign you',
            $message,
            'There is no franchise for an administrator to assign them to — the '
            .'remedy would send them to somebody who cannot help.',
        );

        // What IS missing, and the fact that they are the one standing over it.
        $this->assertStringContainsString('add one where you are already assigned', $message);
        $this->assertStringContainsString('rather than an assignment', $message);
    }

    /**
     * §3's invariant, guarded — because the docblock above leans on it.
     *
     * Refusal::nothingBelow calls the state "off-invariant rather than
     * routine", which is only worth saying if it stays true. These are the four
     * edges: a company is born with a place, its last active one cannot be
     * switched off, a place cannot be moved out from under it, and there is no
     * way to delete one.
     */
    public function test_the_api_will_not_leave_a_company_without_a_place(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/companies', ['name' => 'No place'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('franchise.name');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/companies', [
            'name'      => 'Second',
            'franchise' => ['name' => 'Its only place'],
        ])->assertSuccessful();

        $only = $this->inTenant(
            $alpha['org'],
            fn () => Franchise::where('name', 'Its only place')->firstOrFail(),
        );

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/franchises/{$only->getKey()}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/franchises/{$only->getKey()}", [
                'company_id' => $alpha['company']->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        $this->signedInAs($alpha['admin'])
            ->deleteJson("/api/v1/franchises/{$only->getKey()}")
            ->assertStatus(405);
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

    /**
     * The two gates were one sentence on the Records tab, and it named the
     * wrong one.
     *
     * UserPolicy::update asked `hr.edit` and reach in a single `if` and
     * answered both with "You do not have permission to change this person's
     * account." A branch manager who holds `hr.edit` and opened somebody from
     * another company was therefore told to go and ask for a permission they
     * were already holding.
     */
    public function test_reaching_the_wrong_person_is_not_called_a_missing_permission(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Rhode Island',
        ]));

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.edit'], onlyAt: $alpha['franchise'],
        );

        $stranger = $this->userWithRole(
            $alpha, 'Somebody Else', ['jobs.view'], onlyAt: $elsewhere,
        );

        $message = $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/people/{$stranger->getKey()}", ['is_active' => false])
            ->assertForbidden()
            ->json('message');

        $this->assertStringNotContainsString('do not have permission', $message);
        $this->assertStringContainsString('administrator can widen your assignment', $message);

        // The people list is reach-filtered, so this caller has never been shown
        // that name or that place. A helpful refusal must not be where they
        // first learn either.
        $this->assertStringNotContainsString('Somebody Else', $message);
        $this->assertStringNotContainsString('Rhode Island', $message);

        // And the other gate still answers for itself.
        $reader = $this->userWithRole($alpha, 'Reader', ['hr.view']);

        $this->assertStringContainsString(
            'part of a role',
            $this->signedInAs($reader)
                ->patchJson("/api/v1/hr/people/{$stranger->getKey()}", ['is_active' => false])
                ->assertForbidden()
                ->json('message'),
        );
    }

    /**
     * The same refusal, for somebody standing nowhere at all.
     *
     * One sentence used to cover both, and for this reader both halves of it
     * were false: "this person is assigned outside where you are" names a where
     * they do not have, and "an administrator can widen your assignment" names a
     * widening of nothing. The fix is a FIRST assignment, and sending them to
     * ask for a wider one is how they end up in the wrong conversation.
     */
    public function test_having_no_assignment_is_not_called_being_in_the_wrong_place(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $stranded = $this->userWithNoAssignment($alpha, 'Stranded', ['hr.view', 'hr.edit']);
        $somebody = $this->userWithRole(
            $alpha, 'Somebody Else', ['jobs.view'], onlyAt: $alpha['franchise'],
        );

        $message = $this->signedInAs($stranded)
            ->patchJson("/api/v1/hr/people/{$somebody->getKey()}", ['is_active' => false])
            ->assertForbidden()
            ->json('message');

        $this->assertStringNotContainsString('outside where you are', $message);
        $this->assertStringNotContainsString('widen your assignment', $message);

        $this->assertStringContainsString('not assigned anywhere', $message);
        $this->assertStringContainsString('administrator can assign you', $message);

        // Still not a permission problem — they hold `hr.edit`.
        $this->assertStringNotContainsString('do not have permission', $message);

        // And the target is still not named, whichever branch answered.
        $this->assertStringNotContainsString('Somebody Else', $message);
        $this->assertStringNotContainsString('Alpha F1', $message);
    }

    /**
     * A permission key is a wire name, and R8 keeps those off the screen.
     *
     * BrandingController answered "Missing permission: organizations.edit." —
     * a string that appears nowhere in the product, naming a thing the reader
     * cannot go and look at. Its sibling route answered with nothing at all,
     * which renders as the framework's "This action is unauthorized."
     */
    public function test_the_logo_routes_refuse_in_words_rather_than_in_keys(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['companies.view']);

        foreach ([
            $this->signedInAs($viewer)->postJson('/api/v1/branding/organization'),
            $this->signedInAs($viewer)->deleteJson('/api/v1/branding/organization'),
        ] as $response) {
            $message = $response->assertForbidden()->json('message');

            $this->assertStringNotContainsString('organizations.edit', $message);
            $this->assertStringNotContainsString('This action is unauthorized', $message);
            $this->assertStringContainsString('logo', $message);
            $this->assertStringContainsString('part of a role', $message);
        }
    }

    /**
     * The same literal, one file over, on the screen customers actually use.
     *
     * The round that fixed BrandingController and DataController never grepped
     * for the string, so SettingsController::authorizeEdit went on rendering
     * "Missing permission: organizations.edit." for every write on the Settings
     * screen — PATCH and DELETE alike, since both authorize through it. A
     * permission key is a wire name (R8): it appears nowhere in the product, so
     * the reader cannot go and look at the thing it names, and it says nothing
     * about who could hand it to them.
     */
    public function test_the_settings_routes_refuse_in_words_rather_than_in_keys(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['organizations.view']);

        $scope = ['scope_type' => 'organization', 'scope_id' => $alpha['org']->id];

        foreach ([
            $this->signedInAs($viewer)->patchJson('/api/v1/settings/timezone', $scope + ['value' => 'UTC']),
            $this->signedInAs($viewer)->deleteJson('/api/v1/settings/timezone', $scope),
            $this->signedInAs($viewer)->patchJson(
                '/api/v1/settings/security.require_two_factor', $scope + ['value' => true],
            ),
        ] as $response) {
            $message = $response->assertForbidden()->json('message');

            $this->assertStringNotContainsString('organizations.edit', $message);
            $this->assertStringNotContainsString('Missing permission', $message);
            $this->assertStringNotContainsString('This action is unauthorized', $message);

            // What is missing, and who can hand it over.
            $this->assertStringContainsString('part of a role', $message);
        }

        // The setting is named by its label rather than by its key, so the
        // sentence names something the reader can see on the screen in front
        // of them.
        $this->assertStringContainsString(
            'Default timezone',
            $this->signedInAs($viewer)
                ->patchJson('/api/v1/settings/timezone', $scope + ['value' => 'UTC'])
                ->json('message'),
        );
    }

    /**
     * The reach half of the same method, which carried no remedy either.
     *
     * "Organization-wide settings need an organization-level assignment." and
     * "That scope is outside your assignments." both state a fact and stop.
     * Neither says who could fix it, which is the half of R8 that turns a 403
     * into a dead end.
     */
    public function test_a_settings_scope_out_of_reach_says_who_can_help(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Rhode Island',
        ]));

        // Holds the permission. Stands at one place, and asks about two others.
        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['organizations.view', 'organizations.edit'],
            onlyAt: $alpha['franchise'],
        );

        // Upwards: refused by POSITION, so "ask for the permission" is the wrong
        // advice — they are holding it.
        $upward = $this->signedInAs($manager)->patchJson('/api/v1/settings/timezone', [
            'scope_type' => 'organization',
            'scope_id'   => $alpha['org']->id,
            'value'      => 'UTC',
        ])->assertForbidden()->json('message');

        $this->assertStringContainsString('whole organization', $upward);
        $this->assertStringContainsString('does not flow upwards', $upward);
        $this->assertStringNotContainsString('do not have permission', $upward);

        // Sideways: a place that exists and is not theirs. Named, because they
        // picked it, and followed by the two people who could act.
        $sideways = $this->signedInAs($manager)->patchJson('/api/v1/settings/timezone', [
            'scope_type' => 'franchise',
            'scope_id'   => $elsewhere->getKey(),
            'value'      => 'UTC',
        ])->assertForbidden()->json('message');

        $this->assertStringContainsString('Rhode Island', $sideways);
        $this->assertStringContainsString('administrator can widen your assignment', $sideways);
        $this->assertStringNotContainsString('do not have permission', $sideways);
    }

    /** And the person standing nowhere hears about the assignment, not the role. */
    public function test_a_settings_write_with_no_assignment_names_the_assignment(): void
    {
        $alpha    = $this->provisionTenant('Alpha');
        $stranded = $this->userWithNoAssignment(
            $alpha, 'Stranded', ['organizations.view', 'organizations.edit'],
        );

        $message = $this->signedInAs($stranded)->patchJson('/api/v1/settings/timezone', [
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
            'value'      => 'UTC',
        ])->assertForbidden()->json('message');

        $this->assertStringContainsString('not assigned to any', $message);
        $this->assertStringContainsString('administrator can assign you', $message);
        $this->assertStringNotContainsString('do not have permission', $message);
    }

    /** The same, for the export and import routes that sit outside the policies. */
    public function test_export_and_import_refuse_in_words(): void
    {
        $alpha   = $this->provisionTenant('Alpha');
        $nothing = $this->userWithRole($alpha, 'Nothing', ['jobs.view']);

        $message = $this->signedInAs($nothing)
            ->getJson('/api/v1/exports/customers')
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('export customers', $message);
        $this->assertStringNotContainsString('This action is unauthorized', $message);
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
