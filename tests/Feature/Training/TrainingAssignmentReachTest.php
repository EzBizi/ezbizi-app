<?php

namespace Tests\Feature\Training;

use App\Models\Assignment;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\TrainingAssignment;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Automations\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Giving a guide or a test to a person or a role (training task-9 brief) —
 * `POST /training/assignments`, bounded by §4 reach the identical way
 * onboarding already bounds "who may you assign an employee to"
 * (docs/EZBIZI-HR-PLAN.md, cited generically to §4, not anything
 * HR-specific).
 *
 * `AssignmentReach::mayAssignTo` is asked through the ONE write path this
 * class shares with the `training.assign` automation action
 * (`AssignmentWriter`'s own docblock) — a rule and a person take the
 * identical route, which is what the ActionRunner tests below prove rather
 * than assume.
 */
class TrainingAssignmentReachTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── The brief's own test: same level, never sideways ────────────

    public function test_a_franchise_supervisor_cannot_assign_sideways_into_another_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $supervisor = $this->userInFranchise($tenant, $riverside, 'Supervisor', ['training.assign']);
        $theirs = $this->userInFranchise($tenant, $riverside, 'Theirs', ['training.view']);
        $notTheirs = $this->userInFranchise($tenant, $northgate, 'NotTheirs', ['training.view']);
        $guide = $this->aPublishedOrgGuide($tenant);

        // Within reach: succeeds.
        $this->signedInAs($supervisor)->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $theirs->id,
        ])->assertCreated();

        // Sideways: refused, with a sentence naming the place.
        $refused = $this->signedInAs($supervisor)->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $notTheirs->id,
        ]);

        $refused->assertStatus(403);
        $message = trim((string) $refused->json('message'));
        $this->assertNotSame('', $message);
        $this->assertStringContainsString('Northgate', $message);
        $this->assertStringNotContainsString('§', $message);
        $this->assertStringNotContainsString('AssignmentReach', $message);
        $this->assertStringNotContainsString('immutable', $message);
    }

    /** "Downwards" is allowed, not just "same level" — a company-level assigner reaches every franchise beneath it. */
    public function test_a_company_level_assigner_may_assign_downwards_into_any_of_its_franchises(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $regional = $this->userAtCompany($tenant, 'Regional', ['training.assign']);
        $atRiverside = $this->userInFranchise($tenant, $riverside, 'AtRiverside', ['training.view']);
        $atNorthgate = $this->userInFranchise($tenant, $northgate, 'AtNorthgate', ['training.view']);
        $guide = $this->aPublishedOrgGuide($tenant);

        $this->signedInAs($regional)->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $atRiverside->id,
        ])->assertCreated();

        $this->signedInAs($regional)->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $atNorthgate->id,
        ])->assertCreated();
    }

    // ── Decision 2: exactly one of guide_id/test_id ──────────────────

    public function test_neither_guide_nor_test_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $response = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['guide_id']);
        $this->assertNotSame('', trim((string) $response->json('errors.guide_id.0')));
    }

    public function test_both_guide_and_test_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $guide = $this->aPublishedOrgGuide($tenant);
        $test = $this->aPublishedOrgTest($tenant);
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $response = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'test_id' => $test->id,
            'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['guide_id']);
        $this->assertNotSame('', trim((string) $response->json('errors.guide_id.0')));

        $this->inTenant($tenant['org'], function () use ($guide, $test) {
            $this->assertDatabaseMissing('training_assignments', [
                'guide_id' => $guide->id, 'test_id' => $test->id,
            ]);
        });
    }

    // ── Decision 1/3: a role assignment is one row, checked against every current holder ──

    public function test_a_role_assignment_writes_one_row_and_reaches_every_current_holder_via_learn(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $guide = $this->aPublishedOrgGuide($tenant);

        $role = $this->aRole($tenant, 'Field Tech', ['training.view']);
        $memberA = $this->userWithExistingRole($tenant, $role, $riverside, 'MemberA');
        $memberB = $this->userWithExistingRole($tenant, $role, $riverside, 'MemberB');
        $outsider = $this->userInFranchise($tenant, $riverside, 'Outsider', ['training.view']);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'role', 'assignee_id' => $role->id,
        ])->assertCreated();

        $this->inTenant($tenant['org'], function () use ($guide, $role) {
            $count = TrainingAssignment::query()
                ->where('assignee_type', TrainingAssignment::ROLE)
                ->where('assignee_id', $role->id)
                ->where('guide_id', $guide->id)
                ->count();

            $this->assertSame(1, $count, 'A role assignment must write exactly one row, never one per member.');
        });

        $inA = collect($this->signedInAs($memberA)->getJson('/api/v1/training/learn')->json('assigned'))->pluck('id');
        $inB = collect($this->signedInAs($memberB)->getJson('/api/v1/training/learn')->json('assigned'))->pluck('id');
        $inOutsider = collect($this->signedInAs($outsider)->getJson('/api/v1/training/learn')->json('assigned'))->pluck('id');

        $this->assertContains($guide->id, $inA, 'Every current holder of the role must see the assignment.');
        $this->assertContains($guide->id, $inB, 'Every current holder of the role must see the assignment.');
        $this->assertNotContains($guide->id, $inOutsider, 'Somebody who does not hold the role must not see it.');
    }

    /** The role case of the same sideways rule: one member outside reach refuses the whole assignment. */
    public function test_a_role_assignment_is_refused_when_any_current_member_is_outside_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $supervisor = $this->userInFranchise($tenant, $riverside, 'Supervisor', ['training.assign']);
        $role = $this->aRole($tenant, 'Field Tech', ['training.view']);
        $this->userWithExistingRole($tenant, $role, $riverside, 'InReach');
        $this->userWithExistingRole($tenant, $role, $northgate, 'OutOfReach');
        $guide = $this->aPublishedOrgGuide($tenant);

        $refused = $this->signedInAs($supervisor)->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'role', 'assignee_id' => $role->id,
        ]);

        $refused->assertStatus(403);
        $this->assertNotSame('', trim((string) $refused->json('message')));

        $this->inTenant($tenant['org'], function () use ($guide, $role) {
            $this->assertDatabaseMissing('training_assignments', [
                'assignee_type' => TrainingAssignment::ROLE, 'assignee_id' => $role->id, 'guide_id' => $guide->id,
            ]);
        });
    }

    /** A role with no current holder is nobody's to bound reach against — only organization reach may target it. */
    public function test_an_empty_role_may_only_be_assigned_by_someone_with_organization_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $supervisor = $this->userInFranchise($tenant, $riverside, 'Supervisor', ['training.assign']);
        $role = $this->aRole($tenant, 'Empty Role', ['training.view']);
        $guide = $this->aPublishedOrgGuide($tenant);

        $this->signedInAs($supervisor)->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'role', 'assignee_id' => $role->id,
        ])->assertStatus(403);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'role', 'assignee_id' => $role->id,
        ])->assertCreated();
    }

    // ── The read side: appears for the target, never for anybody else ──

    public function test_the_assignment_appears_in_learn_for_the_target_user_and_not_for_others(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $guide = $this->aPublishedOrgGuide($tenant);

        $target = $this->userInFranchise($tenant, $riverside, 'Target', ['training.view']);
        $someoneElse = $this->userInFranchise($tenant, $riverside, 'SomeoneElse', ['training.view']);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $target->id, 'due_on' => '2026-09-15',
        ])->assertCreated();

        $forTarget = $this->signedInAs($target)->getJson('/api/v1/training/learn')->json('assigned');
        $forOther = $this->signedInAs($someoneElse)->getJson('/api/v1/training/learn')->json('assigned');

        $this->assertContains($guide->id, collect($forTarget)->pluck('id'));
        $this->assertNotContains($guide->id, collect($forOther)->pluck('id'));

        $row = collect($forTarget)->firstWhere('id', $guide->id);
        $this->assertSame('2026-09-15', $row['due_on'] ?? null);
    }

    // ── Concurrency: a repeat assignment of the same target to the same assignee is a no-op ──

    public function test_assigning_the_same_guide_to_the_same_person_twice_does_not_duplicate(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $guide = $this->aPublishedOrgGuide($tenant);
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $first = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ])->assertCreated()->json('data.id');

        $second = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame($first, $second, 'A repeat assignment must hand back the same row, not a second one.');

        $this->inTenant($tenant['org'], function () use ($guide, $learner, $first) {
            $count = TrainingAssignment::query()
                ->where('assignee_type', TrainingAssignment::USER)
                ->where('assignee_id', $learner->id)
                ->where('guide_id', $guide->id)
                ->count();
            $this->assertSame(1, $count);

            $events = AutomationEvent::query()
                ->where('event_key', 'training.assigned')
                ->where('subject_id', $first)
                ->count();
            $this->assertSame(1, $events, 'A duplicate assignment attempt must not raise a second event.');
        });
    }

    // ── The event: an outbox insert carrying only what the trigger promises ──

    public function test_assigning_inserts_an_outbox_row_with_only_the_promised_fields_and_dispatches_nothing(): void
    {
        Bus::fake();

        $tenant = $this->provisionTenant('Alpha');
        $guide = $this->aPublishedOrgGuide($tenant);
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $assignmentId = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id, 'due_on' => '2026-09-15',
        ])->assertCreated()->json('data.id');

        Bus::assertNothingDispatched();

        $this->inTenant($tenant['org'], function () use ($assignmentId, $guide, $learner, $tenant) {
            $event = AutomationEvent::query()
                ->where('event_key', 'training.assigned')
                ->where('subject_id', $assignmentId)
                ->firstOrFail();

            $this->assertSame(TrainingAssignment::class, $event->subject_type);
            $this->assertSame($tenant['admin']->id, $event->actor_id);
            $this->assertNull($event->delivered_at);

            $this->assertEqualsCanonicalizing(
                ['guide_id', 'test_id', 'assignee_type', 'assignee_id', 'assigned_by_user_id', 'due_on'],
                array_keys($event->payload['after']),
            );
            $this->assertSame($guide->id, $event->payload['after']['guide_id']);
            $this->assertNull($event->payload['after']['test_id']);
            $this->assertSame('user', $event->payload['after']['assignee_type']);
            $this->assertSame($learner->id, $event->payload['after']['assignee_id']);
            $this->assertSame($tenant['admin']->id, $event->payload['after']['assigned_by_user_id']);
            $this->assertSame('2026-09-15', $event->payload['after']['due_on']);
        });
    }

    // ── Wiring: a person and a rule take the identical path ─────────

    /**
     * `training.assign` is declared in `AutomationsController::IMPLEMENTED_ACTIONS`
     * and in `ModuleServiceProvider`, but before this task `ActionRunner` had
     * no arm for it and every rule using it recorded a FAILED run. This
     * proves the arm is real: a rule that fires the action must actually
     * write the assignment row, not merely avoid throwing.
     */
    public function test_the_training_assign_automation_action_actually_assigns(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $triggerGuide = $this->aPublishedOrgGuide($tenant, 'Trigger Guide');
        $targetGuide = $this->aPublishedOrgGuide($tenant, 'Target Guide');
        $learner = $this->userInFranchise($tenant, $riverside, 'Learner', ['training.view']);

        $automationId = $this->signedInAs($tenant['admin'])->postJson('/api/v1/automations', [
            'name' => 'Auto-assign the target guide',
            'trigger_key' => 'training.completed',
            // No scope_type/scope_id: omitted means organization-wide, and
            // 'organization' is not itself a legal scope_type value (only
            // 'company'/'franchise' are — AutomationsController::validated).
            'actions' => [
                [
                    'type' => 'training.assign',
                    'guide_id' => $targetGuide->id,
                    'assignee_type' => 'user',
                    'assignee_id' => $learner->id,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/automations/{$automationId}", ['is_active' => true])
            ->assertOk();

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$triggerGuide->id}/seen", ['screen' => 0])
            ->assertOk()
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        $this->inTenant($tenant['org'], function () use ($automationId, $targetGuide, $learner) {
            $event = AutomationEvent::query()
                ->where('event_key', 'training.completed')
                ->latest('occurred_at')
                ->firstOrFail();

            $delivered = app(AutomationEngine::class)->deliver($event);
            $this->assertSame(1, $delivered, 'The rule must have matched and run.');

            $run = AutomationRun::query()->where('automation_id', $automationId)->firstOrFail();
            $this->assertSame(
                AutomationRun::SUCCEEDED,
                $run->status,
                'Expected a SUCCEEDED run; got action_results: '.json_encode($run->action_results),
            );

            $this->assertTrue(
                TrainingAssignment::query()
                    ->where('assignee_type', TrainingAssignment::USER)
                    ->where('assignee_id', $learner->id)
                    ->where('guide_id', $targetGuide->id)
                    ->exists(),
                'The training.assign automation action must actually write an assignment row.',
            );
        });
    }

    /**
     * The reach bound applies to a rule exactly the way it applies to a
     * person: the automation's creator is the actor `taskContext` resolves
     * (ActionRunner's own docblock on [assignTraining]), so a rule created
     * by somebody with narrow reach cannot assign outside it either — the
     * FAILED run this test proves is the rule-shaped version of the 403 the
     * sideways test gets over HTTP.
     */
    public function test_the_training_assign_automation_action_is_refused_outside_its_creators_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $supervisor = $this->userInFranchise($tenant, $riverside, 'Supervisor', [
            'training.assign', 'training.view', 'automations.create', 'automations.view', 'automations.edit',
        ]);
        $triggerGuide = $this->aPublishedOrgGuide($tenant, 'Trigger Guide');
        $targetGuide = $this->aPublishedOrgGuide($tenant, 'Target Guide');
        $notTheirs = $this->userInFranchise($tenant, $northgate, 'NotTheirs', ['training.view']);

        $automationId = $this->signedInAs($supervisor)->postJson('/api/v1/automations', [
            'name' => 'Auto-assign sideways',
            'trigger_key' => 'training.completed',
            'scope_type' => 'franchise',
            'scope_id' => $riverside->id,
            'actions' => [
                [
                    'type' => 'training.assign',
                    'guide_id' => $targetGuide->id,
                    'assignee_type' => 'user',
                    'assignee_id' => $notTheirs->id,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->signedInAs($supervisor)
            ->patchJson("/api/v1/automations/{$automationId}", ['is_active' => true])
            ->assertOk();

        $learner = $this->userInFranchise($tenant, $riverside, 'Learner', ['training.view']);
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$triggerGuide->id}/seen", ['screen' => 0])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($automationId, $targetGuide, $notTheirs) {
            $event = AutomationEvent::query()
                ->where('event_key', 'training.completed')
                ->latest('occurred_at')
                ->firstOrFail();

            app(AutomationEngine::class)->deliver($event);

            $run = AutomationRun::query()->where('automation_id', $automationId)->firstOrFail();
            $this->assertSame(AutomationRun::FAILED, $run->status);
            $error = trim((string) $run->error);
            $this->assertNotSame('', $error);
            // Specifically the §4 refusal — not merely "some failure", which
            // an unimplemented action arm would also produce (that shape is
            // covered by test_the_training_assign_automation_action_actually_assigns
            // in the other direction).
            $this->assertStringContainsString('Northgate', $error);
            $this->assertStringNotContainsString('Unknown automation action', $error);

            $this->assertDatabaseMissing('training_assignments', [
                'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $notTheirs->id, 'guide_id' => $targetGuide->id,
            ]);
        });
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /** @param  array{org: \App\Models\Organization, franchise: Franchise}  $tenant */
    private function aPublishedOrgGuide(array $tenant, string $title = 'Org Guide', int $screenCount = 1): TrainingGuide
    {
        return $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => $title, 'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->screens($screenCount),
        ]));
    }

    private function aPublishedOrgTest(array $tenant, string $title = 'Org Test'): TrainingTest
    {
        return $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null, 'franchise_id' => null, 'guide_id' => null,
            'title' => $title, 'status' => TrainingTest::PUBLISHED,
            'questions' => [],
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    private function screens(int $count): array
    {
        return array_map(
            fn (int $n) => ['title' => "Screen {$n}", 'body' => 'Content.', 'media_kind' => 'none', 'media_url' => null],
            range(1, $count),
        );
    }

    /**
     * `BuildsTenants::userWithRole` always assigns organization-level unless
     * `onlyAt` (a Franchise) is given — this is that same shape aimed at a
     * FRANCHISE explicitly, with its own role per user the same way
     * `userWithRole` builds one, so two calls in one test never collide on
     * a shared role's permissions.
     */
    private function userInFranchise(array $tenant, Franchise $franchise, string $roleName, array $permissions): User
    {
        return $this->userWithRole($tenant, $roleName, $permissions, onlyAt: $franchise);
    }

    /** Same shape as `TrainingProgressTest::userAtCompany` — `userWithRole`'s `onlyAt` only accepts a Franchise. */
    private function userAtCompany(array $tenant, string $roleName, array $permissions): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $roleName, $permissions) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id, 'name' => $roleName, 'is_system' => false,
            ]);
            $role->syncPermissionKeys($permissions);

            $user = User::create([
                'organization_id' => $tenant['org']->id, 'role_id' => $role->id,
                'name' => $roleName, 'email' => $this->emailFor($tenant, $roleName),
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $tenant['org']->id, 'user_id' => $user->id,
                'assigned_company_id' => $tenant['company']->id,
            ]);

            return $user;
        });
    }

    /** A role held by nobody yet — see [userWithExistingRole] for adding members to it. */
    private function aRole(array $tenant, string $name, array $permissions): Role
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $permissions) {
            $role = Role::create(['organization_id' => $tenant['org']->id, 'name' => $name, 'is_system' => false]);
            $role->syncPermissionKeys($permissions);

            return $role;
        });
    }

    /** A user holding an EXISTING role (rather than a fresh one-off role per `userWithRole`), assigned at one franchise. */
    private function userWithExistingRole(array $tenant, Role $role, Franchise $franchise, string $name): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $role, $franchise, $name) {
            $user = User::create([
                'organization_id' => $tenant['org']->id, 'role_id' => $role->id,
                'name' => $name, 'email' => $this->emailFor($tenant, $name),
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $tenant['org']->id, 'user_id' => $user->id,
                'assigned_franchise_id' => $franchise->id,
            ]);

            return $user;
        });
    }

    private function emailFor(array $tenant, string $label): string
    {
        static $n = 0;
        $n++;

        return strtolower(preg_replace('/[^a-z0-9]/i', '', $label)).$n.'@'.$tenant['org']->slug.'.test';
    }
}
