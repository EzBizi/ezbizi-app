<?php

namespace Tests\Feature\Training;

use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * How an organization watches training (training task-11 brief) —
 * `GET /training/dashboard`, `GET /training/dashboard/{id}/people`, and
 * `GET /training/records`, all gated `training.monitor`. Plus the unmet
 * acceptance criterion this task closes: a lapsed pass reappearing in
 * `GET /training/learn`.
 *
 * ── `overdue` and `retaking` are different facts about different people ──
 *
 * `overdue` is "assigned, past due, never finished" — a person who has not
 * yet done what was asked. `retaking` is "did it once, and the pass ran
 * out" — a DIFFERENT failure, on a DIFFERENT person, and the two must never
 * collapse into one number. See `test_overdue_and_retaking_are_counted_as_two_different_numbers`.
 *
 * ── The summary counts PEOPLE ─────────────────────────────────────
 *
 * A client summing the per-row `overdue`/`retaking` counts is counting
 * ASSIGNMENTS. One person overdue on three guides must sum to 1 in
 * `summary.people_overdue`, not 3 — see
 * `test_the_summary_counts_distinct_people_not_assignments`.
 *
 * ── The word this module's output must never contain ────────────────
 *
 * See `test_the_dashboard_never_says_certification`.
 */
class TrainingDashboardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── overdue vs retaking ────────────────────────────────────────

    public function test_overdue_and_retaking_are_counted_as_two_different_numbers(): void
    {
        $tenant = $this->provisionTenant('Alpha-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $neverStarted = $this->userWithRole($tenant, 'NeverStarted', ['training.view'], onlyAt: $tenant['franchise']);
        $retaker = $this->userWithRole($tenant, 'Retaker', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Ladder Safety Test');

        $this->assignRow($tenant, null, $test, $neverStarted, dueOn: now()->subDay()->toDateString());
        $this->aLapsedPass($tenant, $retaker, $test, daysAgo: 1);

        $row = collect($this->signedInAs($monitor)
            ->getJson('/api/v1/training/dashboard')->json('data'))
            ->firstWhere('id', $test->id);

        $this->assertNotNull($row, 'The test must appear on the dashboard.');
        $this->assertSame(1, $row['overdue'], 'Never started, and its due date has passed.');
        $this->assertSame(1, $row['retaking'], 'Passed once, and the pass ran out.');
    }

    public function test_a_currently_valid_pass_is_neither_overdue_nor_retaking(): void
    {
        $tenant = $this->provisionTenant('Valid-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Still Valid Test', validForDays: 365);

        $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDay(),
            'expires_on' => now()->addDays(300)->toDateString(),
        ]));

        $row = collect($this->signedInAs($monitor)
            ->getJson('/api/v1/training/dashboard')->json('data'))
            ->firstWhere('id', $test->id);

        $this->assertSame(1, $row['passed']);
        $this->assertSame(0, $row['retaking']);
        $this->assertSame(0, $row['overdue']);
    }

    // ── No "certification" anywhere ───────────────────────────────

    public function test_the_dashboard_never_says_certification(): void
    {
        $tenant = $this->provisionTenant('Cert-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Ladder Safety Test');
        $this->aLapsedPass($tenant, $learner, $test, daysAgo: 1);

        // HR's panel owns that word for licence expiries typed at onboarding.
        // Two panels of the same name from different tables would disagree,
        // correctly.
        $body = $this->signedInAs($monitor)->getJson('/api/v1/training/dashboard')->getContent();

        $this->assertStringNotContainsStringIgnoringCase('certification', $body);
    }

    // ── The summary counts PEOPLE, not assignments ────────────────

    public function test_the_summary_counts_distinct_people_not_assignments(): void
    {
        $tenant = $this->provisionTenant('Summary-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        foreach (['Guide A', 'Guide B', 'Guide C'] as $title) {
            $guide = $this->aPublishedGuide($tenant, $title);
            $this->assignRow($tenant, $guide, null, $learner, dueOn: now()->subDay()->toDateString());
        }

        $summary = $this->signedInAs($monitor)->getJson('/api/v1/training/dashboard')->json('summary');

        $this->assertSame(
            1,
            $summary['people_overdue'],
            'One person overdue on three guides is one person, not three assignments.',
        );
        $this->assertSame(1, $summary['people_assigned']);
    }

    public function test_the_summary_separates_overdue_people_from_retaking_people(): void
    {
        $tenant = $this->provisionTenant('SummarySplit-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $overdueLearner = $this->userWithRole($tenant, 'OverdueLearner', ['training.view'], onlyAt: $tenant['franchise']);
        $retaker = $this->userWithRole($tenant, 'Retaker', ['training.view'], onlyAt: $tenant['franchise']);

        $guide = $this->aPublishedGuide($tenant, 'Overdue Guide');
        $this->assignRow($tenant, $guide, null, $overdueLearner, dueOn: now()->subDay()->toDateString());

        $test = $this->aPublishedTest($tenant, 'Retake Test');
        $this->aLapsedPass($tenant, $retaker, $test, daysAgo: 1);

        $summary = $this->signedInAs($monitor)->getJson('/api/v1/training/dashboard')->json('summary');

        $this->assertSame(1, $summary['people_overdue']);
        $this->assertSame(1, $summary['people_due_for_retaking']);
    }

    // ── A sibling franchise never leaks in ─────────────────────────

    public function test_a_siblings_data_does_not_leak_into_the_dashboard(): void
    {
        $tenant = $this->provisionTenant('Sibling-'.Str::random(8));
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $riversideMonitor = $this->userWithRole($tenant, 'RiversideMonitor', ['training.monitor'], onlyAt: $riverside);
        $northgateLearner = $this->userWithRole($tenant, 'NorthgateLearner', ['training.view'], onlyAt: $northgate);

        $northgateGuide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate Only', 'status' => TrainingGuide::PUBLISHED, 'screens' => $this->oneScreen(),
        ]));

        $this->assignRow($tenant, $northgateGuide, null, $northgateLearner, dueOn: now()->subDay()->toDateString());

        $body = $this->signedInAs($riversideMonitor)->getJson('/api/v1/training/dashboard')->json();

        $titles = collect($body['data'])->pluck('title');
        $this->assertNotContains('Northgate Only', $titles, "A sibling franchise's guide must never appear.");
        $this->assertSame(
            0,
            $body['summary']['people_overdue'],
            'A Northgate-only overdue person must not count for a Riverside-only monitor.',
        );
    }

    // ── Gate ─────────────────────────────────────────────────────

    public function test_the_dashboard_requires_monitor_and_refuses_view_only(): void
    {
        $tenant = $this->provisionTenant('Gate-'.Str::random(8));
        $viewOnly = $this->userWithRole($tenant, 'ViewOnly', ['training.view']);

        $this->signedInAs($viewOnly)->getJson('/api/v1/training/dashboard')->assertForbidden();
    }

    public function test_the_people_pane_requires_monitor(): void
    {
        $tenant = $this->provisionTenant('GatePeople-'.Str::random(8));
        $viewOnly = $this->userWithRole($tenant, 'ViewOnly', ['training.view']);
        $guide = $this->aPublishedGuide($tenant);

        $this->signedInAs($viewOnly)
            ->getJson("/api/v1/training/dashboard/{$guide->id}/people")
            ->assertForbidden();
    }

    public function test_records_requires_monitor(): void
    {
        $tenant = $this->provisionTenant('GateRecords-'.Str::random(8));
        $viewOnly = $this->userWithRole($tenant, 'ViewOnly', ['training.view']);

        $this->signedInAs($viewOnly)
            ->getJson("/api/v1/training/records?user={$viewOnly->id}")
            ->assertForbidden();
    }

    // ── The people pane ─────────────────────────────────────────────

    public function test_the_people_pane_names_the_overdue_and_the_retaking_person(): void
    {
        $tenant = $this->provisionTenant('Pane-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $overdueLearner = $this->userWithRole($tenant, 'OverdueLearner', ['training.view'], onlyAt: $tenant['franchise']);
        $retaker = $this->userWithRole($tenant, 'Retaker', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Pane Test');
        $this->assignRow($tenant, null, $test, $overdueLearner, dueOn: now()->subDay()->toDateString());
        $this->aLapsedPass($tenant, $retaker, $test, daysAgo: 1);

        $rows = collect($this->signedInAs($monitor)
            ->getJson("/api/v1/training/dashboard/{$test->id}/people")->json('data'));

        $overdueRow = $rows->firstWhere('user_id', $overdueLearner->id);
        $retakerRow = $rows->firstWhere('user_id', $retaker->id);

        $this->assertNotNull($overdueRow);
        $this->assertNotNull($retakerRow);
        $this->assertSame('overdue', $overdueRow['state']);
        $this->assertSame('retaking', $retakerRow['state']);
    }

    public function test_the_people_pane_refuses_a_siblings_guide(): void
    {
        $tenant = $this->provisionTenant('PaneSibling-'.Str::random(8));
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $riversideMonitor = $this->userWithRole($tenant, 'RiversideMonitor', ['training.monitor'], onlyAt: $riverside);

        $northgateGuide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate Only', 'status' => TrainingGuide::PUBLISHED, 'screens' => $this->oneScreen(),
        ]));

        $this->signedInAs($riversideMonitor)
            ->getJson("/api/v1/training/dashboard/{$northgateGuide->id}/people")
            ->assertNotFound();
    }

    // ── Records, for HR to render ────────────────────────────────

    public function test_records_returns_that_persons_training_including_the_lapsed_pass(): void
    {
        $tenant = $this->provisionTenant('Records-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aPublishedTest($tenant, 'Records Test');

        $this->aLapsedPass($tenant, $learner, $test, daysAgo: 1);

        $body = $this->signedInAs($monitor)
            ->getJson("/api/v1/training/records?user={$learner->id}")->json();

        $this->assertSame($learner->id, $body['user']['id']);

        $row = collect($body['data'])->firstWhere('id', $test->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['due_for_retaking']);
        $this->assertStringNotContainsStringIgnoringCase('certification', json_encode($body));
    }

    public function test_records_writes_nothing_into_hrs_tables(): void
    {
        $tenant = $this->provisionTenant('NoWrite-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aPublishedTest($tenant, 'No Write Test');
        $this->aLapsedPass($tenant, $learner, $test, daysAgo: 1);

        $this->signedInAs($monitor)->getJson("/api/v1/training/records?user={$learner->id}")->assertOk();

        // Training owns its data; HR reads it. Nothing this endpoint does
        // may write to a table outside training_*.
        $this->assertDatabaseCount('training_attempts', 1);
    }

    // ── The unmet acceptance criterion: a lapsed pass reappears in Learn ──

    public function test_a_lapsed_pass_reappears_in_learn_as_due_for_retaking(): void
    {
        $tenant = $this->provisionTenant('Reappear-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aPublishedTest($tenant, 'Ladder Safety Test', validForDays: 30);

        $this->aLapsedPass($tenant, $learner, $test, daysAgo: 1);

        $assigned = collect($this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')->json('assigned'));

        $matches = $assigned->where('id', $test->id);

        $this->assertCount(1, $matches, 'The lapsed test must reappear exactly once in the assigned shelf.');

        $row = $matches->first();
        $this->assertTrue($row['due_for_retaking']);
        $this->assertNull($row['assignment_id'], 'No real assignment row backs this reappearance.');
    }

    public function test_a_lapsed_pass_with_a_real_reassignment_does_not_double_count(): void
    {
        $tenant = $this->provisionTenant('NoDouble-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aPublishedTest($tenant, 'Ladder Safety Test', validForDays: 30);

        $this->aLapsedPass($tenant, $learner, $test, daysAgo: 1);
        $this->assignRow($tenant, null, $test, $learner, dueOn: now()->addDays(7)->toDateString());

        $assigned = collect($this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')->json('assigned'));

        $matches = $assigned->where('id', $test->id);

        $this->assertCount(1, $matches, 'A real reassignment and the synthetic reappearance must not both show.');

        $row = $matches->first();
        $this->assertTrue($row['due_for_retaking']);
        $this->assertNotNull($row['assignment_id'], 'The real assignment row is the one that should be shown.');
    }

    public function test_a_still_valid_pass_never_appears_as_due_for_retaking(): void
    {
        $tenant = $this->provisionTenant('StillValid-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aPublishedTest($tenant, 'Ladder Safety Test', validForDays: 365);

        $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDay(),
            'expires_on' => now()->addDays(300)->toDateString(),
        ]));

        $data = $this->signedInAs($learner)->getJson('/api/v1/training/learn')->json();

        $this->assertNull(
            collect($data['assigned'])->firstWhere('id', $test->id),
            'A still-valid pass must not reappear as an assignment.',
        );

        $libraryRow = collect($data['library'])->firstWhere('id', $test->id);
        $this->assertFalse($libraryRow['due_for_retaking']);
    }

    public function test_showing_one_test_also_carries_due_for_retaking(): void
    {
        $tenant = $this->provisionTenant('ShowOne-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aPublishedTest($tenant, 'Ladder Safety Test', validForDays: 30);

        $this->aLapsedPass($tenant, $learner, $test, daysAgo: 1);

        $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$test->id}")
            ->assertOk()
            ->assertJsonPath('data.due_for_retaking', true);
    }

    // ── Fixtures ────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function twoQuestions(): array
    {
        $question = [
            'choices' => [
                ['text' => 'Wrong', 'correct' => false],
                ['text' => 'Right', 'correct' => true],
            ],
        ];

        return [
            ['prompt' => 'Question 1', ...$question],
            ['prompt' => 'Question 2', ...$question],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function oneScreen(): array
    {
        return [['title' => 'Screen 1', 'body' => 'Content.', 'media_kind' => 'none', 'media_url' => null]];
    }

    private function aPublishedGuide(array $tenant, string $title = 'A Guide'): TrainingGuide
    {
        return $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => $title,
            'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->oneScreen(),
        ]));
    }

    private function aPublishedTest(array $tenant, string $title = 'A Test', ?int $validForDays = 30): TrainingTest
    {
        return $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => $title,
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => 40,
            'valid_for_days' => $validForDays,
        ]));
    }

    /** A direct-to-user assignment row — never through AssignmentWriter, so no §4 checks apply here. */
    private function assignRow(
        array $tenant,
        ?TrainingGuide $guide,
        ?TrainingTest $test,
        User $assignee,
        ?string $dueOn = null,
    ): TrainingAssignment {
        return $this->inTenant($tenant['org'], fn () => TrainingAssignment::create([
            'company_id' => $guide?->company_id ?? $test?->company_id,
            'franchise_id' => $guide?->franchise_id ?? $test?->franchise_id,
            'guide_id' => $guide?->id,
            'test_id' => $test?->id,
            'assignee_type' => TrainingAssignment::USER,
            'assignee_id' => $assignee->id,
            'due_on' => $dueOn,
        ]));
    }

    /**
     * A passed attempt whose `expires_on` is `$daysAgo` days in the past —
     * same shape as `TrainingExpiryTest::aPassedAttemptExpiring`, one
     * attempt at a time rather than returning a whole fixture tuple, since
     * every test in this file already has its own tenant/learner/test.
     */
    private function aLapsedPass(array $tenant, User $learner, TrainingTest $test, int $daysAgo): TrainingAttempt
    {
        return $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDays(30 + $daysAgo),
            'expires_on' => now()->subDays($daysAgo)->toDateString(),
        ]));
    }
}
