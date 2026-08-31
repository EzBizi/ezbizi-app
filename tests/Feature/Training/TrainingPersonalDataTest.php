<?php

namespace Tests\Feature\Training;

use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingProgress;
use App\Models\TrainingTest as TrainingTestModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * §9's data inventory and §10's retention, for the three Training tables Task 1
 * left minimal: TrainingAssignment, TrainingProgress, TrainingAttempt.
 *
 * TrainingGuide and TrainingTest already decided in Task 1 — this file is about
 * the tables that record something about a PERSON: who was assigned what, how
 * far they got, and how they did on a test.
 */
class TrainingPersonalDataTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_every_training_table_has_decided_about_retention(): void
    {
        foreach ([TrainingGuide::class, TrainingTestModel::class, TrainingAssignment::class,
            TrainingProgress::class, TrainingAttempt::class] as $model) {
            $this->assertNotSame('', trim($model::retention()->sentence()),
                "{$model} must state a retention policy with a reason.");
        }
    }

    public function test_an_attempt_is_findable_as_personal_data_about_its_learner(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $tenant['admin'];

        $this->assertNotNull(
            TrainingAttempt::personalDataAbout($learner),
            'A test attempt is a record of a named person being examined.'
        );
    }

    public function test_progress_is_findable_as_personal_data_about_its_reader(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $reader = $tenant['admin'];

        $this->assertNotNull(
            TrainingProgress::personalDataAbout($reader),
            'A progress row is a record of a named person reading a guide.'
        );
    }

    public function test_personal_data_about_returns_null_for_a_subject_that_is_not_a_user(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->assertNull(TrainingAttempt::personalDataAbout($tenant['org']));
        $this->assertNull(TrainingProgress::personalDataAbout($tenant['org']));
        $this->assertNull(TrainingAssignment::personalDataAbout($tenant['org']));
    }

    /**
     * The subtle case the brief calls out. `assignee_id` means a different
     * thing depending on `assignee_type`: for a "role" assignment it is a role
     * id, not a person, even when — forced here to prove the guard actually
     * checks the type rather than getting lucky — it happens to be
     * byte-for-byte the same UUID as a real user's id.
     */
    public function test_a_role_assignment_never_matches_a_user_sharing_its_assignee_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $tenant['admin'];

        [$userAssignmentId, $roleAssignmentId] = $this->inTenant($tenant['org'], function () use ($tenant, $learner) {
            $guide = TrainingGuide::factory()->create(['company_id' => $tenant['company']->id]);

            $roleAssignment = TrainingAssignment::create([
                'company_id' => $tenant['company']->id,
                'guide_id' => $guide->id,
                'assignee_type' => TrainingAssignment::ROLE,
                'assignee_id' => $learner->id, // deliberate UUID collision with a real user
            ]);

            $userAssignment = TrainingAssignment::create([
                'company_id' => $tenant['company']->id,
                'guide_id' => $guide->id,
                'assignee_type' => TrainingAssignment::USER,
                'assignee_id' => $learner->id,
            ]);

            return [$userAssignment->id, $roleAssignment->id];
        });

        $matchedIds = $this->inTenant($tenant['org'], fn () => TrainingAssignment::personalDataAbout($learner)
            ->pluck('id')
            ->all());

        $this->assertContains($userAssignmentId, $matchedIds,
            'A user-type assignment to this person must match.');
        $this->assertNotContains($roleAssignmentId, $matchedIds,
            'A role-type assignment sharing the same id by coincidence must NOT match — '
            .'assignee_id is a role id there, not a person.');
    }

    public function test_an_assignment_matches_the_person_who_created_it_even_when_assigned_to_a_role(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $creator = $tenant['admin'];

        $assignmentId = $this->inTenant($tenant['org'], function () use ($tenant, $creator) {
            $guide = TrainingGuide::factory()->create(['company_id' => $tenant['company']->id]);

            return TrainingAssignment::create([
                'company_id' => $tenant['company']->id,
                'guide_id' => $guide->id,
                'assignee_type' => TrainingAssignment::ROLE,
                'assignee_id' => (string) Str::uuid(),
                'assigned_by_user_id' => $creator->id,
            ])->id;
        });

        $matchedIds = $this->inTenant($tenant['org'], fn () => TrainingAssignment::personalDataAbout($creator)
            ->pluck('id')
            ->all());

        $this->assertContains($assignmentId, $matchedIds,
            'An assignment is a fact about who created it, as well as who received it.');
    }

    public function test_a_null_assigner_does_not_break_the_match_on_the_assignee(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $tenant['admin'];

        $this->inTenant($tenant['org'], function () use ($tenant, $learner) {
            $guide = TrainingGuide::factory()->create(['company_id' => $tenant['company']->id]);

            TrainingAssignment::create([
                'company_id' => $tenant['company']->id,
                'guide_id' => $guide->id,
                'assignee_type' => TrainingAssignment::USER,
                'assignee_id' => $learner->id,
                'assigned_by_user_id' => null,
            ]);
        });

        $matched = $this->inTenant($tenant['org'], fn () => TrainingAssignment::personalDataAbout($learner)->count());

        $this->assertSame(1, $matched, 'A null assigned_by_user_id must not stop the assignee half from matching.');
    }
}
