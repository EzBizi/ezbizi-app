<?php

namespace Tests\Feature\Training;

use App\Models\Assignment;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Franchise;
use App\Models\Notification;
use App\Models\Role;
use App\Models\TrainingAssignment;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Automations\AutomationEngine;
use App\Support\Training\AssignmentReach;
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

    // ── Round 3: an unplaced assignee is the organization's to bound, not always-refused ──

    /**
     * Round 3 review: unifying `mayAssignTo`/`explain` (round 2) dropped
     * the `canActOnOrganization` arm the old, separate `coversEveryAssignment`
     * had for an assignee with NO `Assignment` row at all — `explain` fell
     * straight to `Refusal::noReach`, so an assigner who genuinely reaches
     * the whole organization was refused for a person assigned nowhere,
     * with a sentence naming their OWN organization as "outside where you
     * are assigned". Tested directly against `AssignmentReach`, not through
     * the HTTP endpoint, because `assertLearnerCanSee` also refuses an
     * unplaced learner downstream (zero place reach means zero
     * `InventoryVisibility`) — routing through HTTP would prove the request
     * still 422s either way and say nothing about which check produced it,
     * which is exactly what round 3 found wrong.
     */
    public function test_an_organization_reach_assigner_may_assign_to_a_completely_unplaced_person(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $unplaced = $this->userAssignedNowhere($tenant, 'Unplaced');

        // AssignmentReach::mayAssignTo reads the assignee's `assignments`
        // relation, which is tenant-scoped (BelongsToTenant) — called
        // directly rather than through a request, so it needs the same
        // TenantContext a real request would already have resolved.
        $this->inTenant($tenant['org'], function () use ($tenant, $unplaced) {
            $this->assertTrue(
                AssignmentReach::mayAssignTo($tenant['admin'], $unplaced),
                'An assigner with organization-level reach must reach somebody assigned nowhere at all.',
            );
        });
    }

    /** The other half of the same restored branch: somebody NOT at organization reach is still refused. */
    public function test_a_franchise_level_assigner_may_not_assign_to_a_completely_unplaced_person(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $supervisor = $this->userInFranchise($tenant, $tenant['franchise'], 'Supervisor', ['training.assign']);
        $unplaced = $this->userAssignedNowhere($tenant, 'Unplaced');

        $this->inTenant($tenant['org'], function () use ($tenant, $supervisor, $unplaced) {
            $this->assertFalse(AssignmentReach::mayAssignTo($supervisor, $unplaced));

            $message = trim(AssignmentReach::explain($supervisor, $unplaced)->message());
            $this->assertNotSame('', $message);
            $this->assertStringContainsString($tenant['org']->name, $message);
        });
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

        // Organization reach — see the role-reach tests below for why a
        // franchise-level assigner is refused for a role target regardless
        // of where its current members happen to stand.
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

    /**
     * Round 2 ruling: a role target is bounded by ORGANIZATION reach,
     * always — never by its current membership. Proven here against the
     * exact case a franchise-level rule would get RIGHT under the old,
     * per-member rule and WRONG under this one being absent: every current
     * member is reachable, so an assignment based on today's membership
     * would have been allowed — and is refused anyway, because the promise
     * is about whoever the role ever holds, not who holds it right now.
     */
    public function test_a_role_assignment_is_refused_to_a_non_organization_assigner_even_when_every_current_member_is_reachable(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $supervisor = $this->userInFranchise($tenant, $riverside, 'Supervisor', ['training.assign']);
        $role = $this->aRole($tenant, 'Field Tech', ['training.view']);
        $this->userWithExistingRole($tenant, $role, $riverside, 'OnlyMemberIsReachable');
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

    /**
     * The two-step bypass the round-2 review proved against the old,
     * per-member rule: a Riverside supervisor's role assignment, allowed
     * today because the role's only member is at Riverside, would have
     * quietly covered a Northgate hire added to that role tomorrow — with
     * no re-check when the membership changed. Since reach for a role no
     * longer depends on membership at all, the supervisor cannot even reach
     * the FIRST step: refused before there is anything to add anybody to.
     */
    public function test_a_role_target_cannot_be_used_to_smuggle_training_onto_a_franchise_added_later(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $supervisor = $this->userInFranchise($tenant, $riverside, 'Supervisor', ['training.assign']);
        $role = $this->aRole($tenant, 'Field Tech', ['training.view']);
        $this->userWithExistingRole($tenant, $role, $riverside, 'RiversideMember');
        $guide = $this->aPublishedOrgGuide($tenant);

        $this->signedInAs($supervisor)->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'role', 'assignee_id' => $role->id,
        ])->assertStatus(403);

        // The bypass this closes: had the assignment above been allowed, a
        // Northgate hire given the SAME role afterward would have inherited
        // it with no re-check — decision 1 (one row, read at Learn time,
        // never re-verified) is what makes that durable rather than a
        // snapshot. Demonstrated by adding the member anyway (as the org
        // admin would when actually hiring) and confirming there is still
        // no row a Northgate member could ever have inherited.
        $this->userWithExistingRole($tenant, $role, $northgate, 'LaterNorthgateHire');

        $this->inTenant($tenant['org'], function () use ($guide, $role) {
            $this->assertDatabaseMissing('training_assignments', [
                'assignee_type' => TrainingAssignment::ROLE, 'assignee_id' => $role->id, 'guide_id' => $guide->id,
            ]);
        });
    }

    /** An empty role is likewise organization-reach-only — membership (none, one, many) never changes the answer. */
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

    /** An organization-level assigner may target ANY role — mixed-place membership included — matching decision 1's read-time union. */
    public function test_an_organization_level_assigner_may_assign_to_a_role_spanning_several_franchises(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $role = $this->aRole($tenant, 'Field Tech', ['training.view']);
        $this->userWithExistingRole($tenant, $role, $riverside, 'AtRiverside');
        $this->userWithExistingRole($tenant, $role, $northgate, 'AtNorthgate');
        $guide = $this->aPublishedOrgGuide($tenant);

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

        // Repeat, identical body — a genuine no-op, so 200 (not 201: nothing
        // was newly created) and the same row's id.
        $second = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ])->assertOk()->json('data.id');

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

    /**
     * Round 2 fix: a repeat naming a DIFFERENT due date used to come back
     * `201` carrying the OLD date, with nothing saying the new one was
     * ignored. It now updates the existing row's due date in place and
     * answers `200`.
     */
    public function test_a_repeat_assignment_naming_a_new_due_date_updates_it_in_place(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $guide = $this->aPublishedOrgGuide($tenant);
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $first = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id, 'due_on' => '2026-09-01',
        ])->assertCreated()->json('data.id');

        $second = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id, 'due_on' => '2026-10-15',
        ])->assertOk();

        $this->assertSame($first, $second->json('data.id'));
        $this->assertSame('2026-10-15', $second->json('data.due_on'));

        $this->inTenant($tenant['org'], function () use ($guide, $learner) {
            $this->assertDatabaseHas('training_assignments', [
                'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $learner->id,
                'guide_id' => $guide->id, 'due_on' => '2026-10-15',
            ]);
            $this->assertSame(
                1,
                TrainingAssignment::query()->where('guide_id', $guide->id)
                    ->where('assignee_id', $learner->id)->count(),
                'Still one row — the date changed on the SAME row, not a second one.',
            );
        });
    }

    /**
     * Round 3 review: the round-2 fix wrote `$input['due_on'] ?? null`,
     * which read "the field was not sent" and "the field was sent as
     * null" as the same thing — a repeat with NO `due_on` key at all
     * erased a stored date rather than leaving it alone, silently, under a
     * `200`. This is the exact regression, proven directly: the second
     * `POST` omits `due_on` entirely.
     */
    public function test_a_repeat_assignment_with_no_due_on_field_leaves_the_stored_date_alone(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $guide = $this->aPublishedOrgGuide($tenant);
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id, 'due_on' => '2026-09-01',
        ])->assertCreated();

        // No 'due_on' key at all — not even null.
        $second = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ])->assertOk();

        $this->assertSame(
            '2026-09-01',
            $second->json('data.due_on'),
            'A repeat that never mentioned due_on must not erase the one already stored.',
        );

        $this->inTenant($tenant['org'], function () use ($guide, $learner) {
            $this->assertDatabaseHas('training_assignments', [
                'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $learner->id,
                'guide_id' => $guide->id, 'due_on' => '2026-09-01',
            ]);
        });
    }

    /** The third state: due_on sent as an EXPLICIT null clears a stored date on purpose. */
    public function test_a_repeat_assignment_explicitly_sending_a_null_due_on_clears_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $guide = $this->aPublishedOrgGuide($tenant);
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id, 'due_on' => '2026-09-01',
        ])->assertCreated();

        $second = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id, 'due_on' => null,
        ])->assertOk();

        $this->assertNull($second->json('data.due_on'));

        $this->inTenant($tenant['org'], function () use ($guide, $learner) {
            $this->assertDatabaseHas('training_assignments', [
                'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $learner->id,
                'guide_id' => $guide->id, 'due_on' => null,
            ]);
        });
    }

    /**
     * The automation path is where round 3 says this is serious, not
     * cosmetic: `ActionRunner::assignTraining` used to pass a `due_on` key
     * unconditionally (`$action['due_on'] ?? null`), so a rule that never
     * configured a date wiped one a person had set by hand, every time it
     * fired against an existing assignment. Proven directly: a person sets
     * a due date, then a rule with NO `due_on` in its own action config
     * fires against the SAME (guide, learner) pair, and the date survives.
     */
    public function test_the_training_assign_automation_action_does_not_erase_a_due_date_the_rule_never_mentioned(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $triggerGuide = $this->aPublishedOrgGuide($tenant, 'Trigger Guide');
        $targetGuide = $this->aPublishedOrgGuide($tenant, 'Target Guide');
        $learner = $this->userInFranchise($tenant, $riverside, 'Learner', ['training.view']);

        // A person set a due date by hand first.
        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $targetGuide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
            'due_on' => '2026-12-01',
        ])->assertCreated();

        // A rule with no due_on in its own action config.
        $automationId = $this->signedInAs($tenant['admin'])->postJson('/api/v1/automations', [
            'name' => 'Auto-assign, no date',
            'trigger_key' => 'training.completed',
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
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($automationId, $targetGuide, $learner) {
            $event = AutomationEvent::query()
                ->where('event_key', 'training.completed')
                ->latest('occurred_at')
                ->firstOrFail();

            app(AutomationEngine::class)->deliver($event);

            $run = AutomationRun::query()->where('automation_id', $automationId)->firstOrFail();
            $this->assertSame(AutomationRun::SUCCEEDED, $run->status, json_encode($run->action_results));

            $this->assertDatabaseHas('training_assignments', [
                'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $learner->id,
                'guide_id' => $targetGuide->id, 'due_on' => '2026-12-01',
            ]);
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

    // ── Round 2: the permission gate must hold on BOTH paths ─────────

    /**
     * Round 2 review found `training.assign` checked ONLY in
     * `AssignmentsController::store` — the per-type action check in
     * `AutomationsController` only covered the action types it enumerated,
     * and `training.assign` was not one of them, so a rule using it
     * ran with no permission check anywhere: `training.view` plus the
     * automation permissions was enough to write a rule that did what a
     * direct `POST` correctly refused. Proven both ways on the SAME
     * under-permissioned person, so this cannot pass by accident on one
     * path while staying broken on the other.
     */
    public function test_training_assign_is_refused_both_directly_and_through_an_automation_without_the_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        // Deliberately NOT training.assign.
        $underPermissioned = $this->userInFranchise($tenant, $riverside, 'UnderPermissioned', [
            'training.view', 'automations.create', 'automations.view', 'automations.edit',
        ]);

        $triggerGuide = $this->aPublishedOrgGuide($tenant, 'Trigger Guide');
        $targetGuide = $this->aPublishedOrgGuide($tenant, 'Target Guide');
        $learner = $this->userInFranchise($tenant, $riverside, 'Learner', ['training.view']);

        // Direct path.
        $direct = $this->signedInAs($underPermissioned)->postJson('/api/v1/training/assignments', [
            'guide_id' => $targetGuide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ]);
        $direct->assertStatus(403);
        $this->assertStringContainsString('permission', (string) $direct->json('message'));

        $this->inTenant($tenant['org'], function () use ($targetGuide, $learner) {
            $this->assertDatabaseMissing('training_assignments', [
                'guide_id' => $targetGuide->id, 'assignee_id' => $learner->id,
            ]);
        });

        // Automation path — the SAME person builds a rule that does the
        // identical thing instead of clicking the refused button.
        $automationId = $this->signedInAs($underPermissioned)->postJson('/api/v1/automations', [
            'name' => 'Assign without the permission',
            'trigger_key' => 'training.completed',
            'scope_type' => 'franchise',
            'scope_id' => $riverside->id,
            'actions' => [
                [
                    'type' => 'training.assign',
                    'guide_id' => $targetGuide->id,
                    'assignee_type' => 'user',
                    'assignee_id' => $learner->id,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->signedInAs($underPermissioned)
            ->patchJson("/api/v1/automations/{$automationId}", ['is_active' => true])
            ->assertOk();

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$triggerGuide->id}/seen", ['screen' => 0])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($automationId, $targetGuide, $learner) {
            $event = AutomationEvent::query()
                ->where('event_key', 'training.completed')
                ->latest('occurred_at')
                ->firstOrFail();

            app(AutomationEngine::class)->deliver($event);

            $run = AutomationRun::query()->where('automation_id', $automationId)->firstOrFail();
            $this->assertSame(
                AutomationRun::FAILED,
                $run->status,
                'A rule using training.assign without the permission must not succeed.',
            );
            $this->assertStringContainsString('permission', strtolower((string) $run->error));

            $this->assertDatabaseMissing('training_assignments', [
                'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $learner->id, 'guide_id' => $targetGuide->id,
            ]);
        });
    }

    // ── Round 2: fail-open closed — a refusal must never write anyway ──

    /**
     * Round 2 review found `mayAssignTo` refuses an INACTIVE assignee while
     * the old, separate `explain()` never checked that at all and fell
     * through to `Response::allow()` — a no-op on `authorize()`, so the
     * write proceeded anyway. `AssignmentReach` now derives both from one
     * shared check (`refusalForAssignee`), which cannot disagree with
     * itself. Proven directly against the exact case that broke it.
     */
    public function test_assigning_to_an_inactive_person_is_refused_and_nothing_is_written(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $guide = $this->aPublishedOrgGuide($tenant);
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $this->inTenant($tenant['org'], fn () => $learner->forceFill(['is_active' => false])->save());

        $refused = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ]);

        $refused->assertStatus(403);
        $this->assertNotSame('', trim((string) $refused->json('message')));

        $this->inTenant($tenant['org'], function () use ($guide, $learner) {
            $this->assertDatabaseMissing('training_assignments', [
                'assignee_type' => TrainingAssignment::USER, 'assignee_id' => $learner->id, 'guide_id' => $guide->id,
            ]);
        });
    }

    // ── Round 2: a dud target — draft, or outside the learner's place ──

    public function test_a_draft_guide_cannot_be_assigned(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $draft = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Unfinished Guide', 'status' => TrainingGuide::DRAFT,
            'screens' => $this->screens(1),
        ]));
        $learner = $this->userInFranchise($tenant, $tenant['franchise'], 'Learner', ['training.view']);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $draft->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ])->assertNotFound();

        $this->inTenant($tenant['org'], function () use ($draft, $learner) {
            $this->assertDatabaseMissing('training_assignments', [
                'guide_id' => $draft->id, 'assignee_id' => $learner->id,
            ]);
        });
    }

    /**
     * A guide filed at a sibling franchise is PUBLISHED and really exists,
     * so `findOrFail` alone would have let this through — but a learner
     * outside its place never sees it via `/learn`, so the row would be a
     * `201` that can never come true (round 2 review).
     */
    public function test_a_guide_outside_the_learners_place_cannot_be_assigned_to_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $sibling = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate only', 'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->screens(1),
        ]));
        $learner = $this->userInFranchise($tenant, $riverside, 'Learner', ['training.view']);

        $refused = $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $sibling->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ]);

        $refused->assertStatus(422)->assertJsonValidationErrors(['guide_id']);
        $this->assertNotSame('', trim((string) $refused->json('errors.guide_id.0')));

        $this->inTenant($tenant['org'], function () use ($sibling, $learner) {
            $this->assertDatabaseMissing('training_assignments', [
                'guide_id' => $sibling->id, 'assignee_id' => $learner->id,
            ]);
        });
    }

    // ── Round 2: CarriesAutomationScope must be proven to NARROW, not merely be present ──

    /**
     * `TrainingAssignment implements CarriesAutomationScope` was reviewed
     * and confirmed correct in isolation — but removing the interface
     * entirely left the whole suite green, because nothing exercised
     * `NotificationDispatcher::audienceFor` against a real `notify` rule
     * the way Task 7/8's own equivalents do
     * (`TrainingProgressTest`/`TrainingGradingTest`'s
     * `test_automation_scope_narrows_the_audience_not_merely_present`,
     * whose `rule()`/`drain()` apparatus this copies). This is that proof
     * for Task 9.
     */
    public function test_automation_scope_narrows_who_is_told_about_an_assignment(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $learner = $this->userInFranchise($tenant, $riverside, 'Learner', ['training.view']);
        $coworker = $this->userInFranchise($tenant, $riverside, 'Coworker', []);
        $outsider = $this->userInFranchise($tenant, $northgate, 'Outsider', []);
        $guide = $this->aPublishedOrgGuide($tenant);

        $this->rule($tenant, 'training.assigned', null, null, actions: [
            ['type' => 'notify', 'message' => 'Somebody was given training.'],
        ]);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/training/assignments', [
            'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $learner->id,
        ])->assertCreated();

        $this->drain($tenant);

        $told = $this->inTenant($tenant['org'], fn () => Notification::query()
            ->where('source', 'automation')->pluck('user_id')->all());

        $this->assertNotEmpty($told, 'Nobody was told at all, so this proves nothing.');

        $this->assertContains(
            $coworker->id,
            $told,
            "The learner's own franchise coworker was not told — the scope did not reach the right "
                .'place, which a [null, null] stub would also produce.',
        );

        $this->assertNotContains(
            $outsider->id,
            $told,
            'Somebody at another franchise was told a named learner was given training — the leak '
                .'this method exists to close.',
        );
    }

    /**
     * `automationScope()` read directly, the shape
     * `TrainingProgressTest`/`TrainingGradingTest` already hold this
     * model's siblings to — the test above only proves the audience
     * narrows for ONE fixture; this proves the two words the method
     * itself returns, across every shape its own docblock claims:
     * franchise, company, unassigned, and a ROLE assignment.
     *
     * An organization-scoped case cannot stand alone: `[null, null]` is a
     * CORRECT answer for an unassigned learner and for a role assignment,
     * so a mapping flattened to `[null, null]` for every case would pass a
     * test that only ever checked those two. The franchise and company
     * cases are the ones that must come back with a real place, and they
     * are asserted first for that reason.
     */
    public function test_automation_scope_reads_the_learners_place_or_falls_back(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $guide = $this->aPublishedOrgGuide($tenant);

        $franchiseLearner = $this->userInFranchise($tenant, $riverside, 'FranchiseLearner', ['training.view']);
        $companyLearner = $this->userAtCompany($tenant, 'CompanyLearner', ['training.view']);
        $unassignedLearner = $this->userAssignedNowhere($tenant, 'UnassignedLearner');
        $role = $this->aRole($tenant, 'Trainee', ['training.view']);

        [$franchiseScope, $companyScope, $unassignedScope, $roleScope] = $this->inTenant(
            $tenant['org'],
            function () use ($guide, $franchiseLearner, $companyLearner, $unassignedLearner, $role) {
                $forFranchise = TrainingAssignment::create([
                    'guide_id' => $guide->id,
                    'assignee_type' => TrainingAssignment::USER,
                    'assignee_id' => $franchiseLearner->id,
                ]);
                $forCompany = TrainingAssignment::create([
                    'guide_id' => $guide->id,
                    'assignee_type' => TrainingAssignment::USER,
                    'assignee_id' => $companyLearner->id,
                ]);
                $forUnassigned = TrainingAssignment::create([
                    'guide_id' => $guide->id,
                    'assignee_type' => TrainingAssignment::USER,
                    'assignee_id' => $unassignedLearner->id,
                ]);
                $forRole = TrainingAssignment::create([
                    'guide_id' => $guide->id,
                    'assignee_type' => TrainingAssignment::ROLE,
                    'assignee_id' => $role->id,
                ]);

                return [
                    $forFranchise->automationScope(),
                    $forCompany->automationScope(),
                    $forUnassigned->automationScope(),
                    $forRole->automationScope(),
                ];
            },
        );

        $this->assertSame(
            ['franchise', $riverside->id],
            $franchiseScope,
            'A franchise-placed learner must resolve to their own franchise, not [null, null] — '
                .'the shape a flattened mapping would also produce.',
        );
        $this->assertSame(
            ['company', $tenant['company']->id],
            $companyScope,
            'A company-placed learner must resolve to their own company.',
        );
        $this->assertSame([null, null], $unassignedScope);
        $this->assertSame(
            [null, null],
            $roleScope,
            'A role names nobody in particular — User::find on the role id finds nobody, so this '
                .'must fall through to the same conservative [null, null] an unplaced person gets, '
                .'not throw and not silently resolve to whoever happens to hold a matching id.',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /**
     * A real, active automation with the given trigger/scope/actions —
     * copied from `TrainingGradingTest::rule` (task-8's own copy of the
     * identical helper), built here again rather than shared per the
     * constraint that test helpers stay private to their own class.
     */
    private function rule(
        array $tenant,
        string $trigger,
        ?string $scopeType,
        ?string $scopeId,
        array $actions,
    ): Automation {
        return $this->inTenant($tenant['org'], function () use ($tenant, $trigger, $scopeType, $scopeId, $actions) {
            $automation = Automation::create([
                'organization_id' => $tenant['org']->id,
                'name' => "When {$trigger} at ".($scopeId ?? 'anywhere'),
                'trigger_key' => $trigger,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'is_active' => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $tenant['org']->id,
                'automation_id' => $automation->id,
                'version' => 1,
                'conditions' => [],
                'actions' => $actions,
                'created_at' => now(),
            ]);

            $automation->forceFill(['active_version_id' => $version->id])->save();

            return $automation->fresh();
        });
    }

    /** Deliver everything sitting in the outbox, the way the scheduled sweep does. */
    private function drain(array $tenant): void
    {
        $engine = app(AutomationEngine::class);

        $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->whereNull('delivered_at')
            ->orderBy('occurred_at')
            ->get()
            ->each(fn (AutomationEvent $e) => $engine->deliver($e)));
    }

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

    /**
     * A user with NO `Assignment` row at all — `userWithRole` always
     * creates one (organization-level by default), so this is the one
     * shape it cannot build. Same fixture `TrainingProgressTest` owns under
     * this name, duplicated here per the constraint on shared test helpers.
     */
    private function userAssignedNowhere(array $tenant, string $roleName): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $roleName) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id, 'name' => $roleName, 'is_system' => false,
            ]);
            $role->syncPermissionKeys(['training.view']);

            return User::create([
                'organization_id' => $tenant['org']->id, 'role_id' => $role->id,
                'name' => $roleName, 'email' => $this->emailFor($tenant, $roleName),
                'password' => 'a-real-password', 'is_active' => true,
            ]);
        });
    }

    private function emailFor(array $tenant, string $label): string
    {
        static $n = 0;
        $n++;

        return strtolower(preg_replace('/[^a-z0-9]/i', '', $label)).$n.'@'.$tenant['org']->slug.'.test';
    }
}
