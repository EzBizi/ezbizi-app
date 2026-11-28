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

    // Fixtures write UTC days; the code reads the viewer's local day. Pin
    // midday UTC so both name the same day (00-05 UTC they differ).
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow(now()->utc()->setTime(16, 0));
    }

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

    /**
     * `DashboardController::row`'s `kind` (`'guide'` or `'test'`) is the
     * ONLY thing that tells `client/lib/screens/training/dashboard_pane.dart`
     * which of `guide_id`/`test_id` to post to `POST /training/assignments`
     * (`AssignmentsController::store` needs exactly one). Deleting
     * `'kind' => $kind` from `row()` left this suite green before this
     * test existed, because nothing pinned the value — pin both rows here
     * so a guide row can never silently start claiming `kind: 'test'` or
     * vice versa.
     */
    public function test_each_row_reports_its_own_kind(): void
    {
        $tenant = $this->provisionTenant('Alpha-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);

        $guide = $this->aPublishedGuide($tenant, 'A Guide');
        $test = $this->aPublishedTest($tenant, 'A Test');

        $rows = collect($this->signedInAs($monitor)->getJson('/api/v1/training/dashboard')->json('data'));

        $guideRow = $rows->firstWhere('id', $guide->id);
        $testRow = $rows->firstWhere('id', $test->id);

        $this->assertNotNull($guideRow, 'The guide must appear on the dashboard.');
        $this->assertNotNull($testRow, 'The test must appear on the dashboard.');
        $this->assertSame('guide', $guideRow['kind']);
        $this->assertSame('test', $testRow['kind']);
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

    /**
     * Important 5 (task-11 review): `statesFor`'s docblock used to claim a
     * person outside all four states was always ASSIGNED-but-not-yet-due.
     * That was false — Training has a LIBRARY as well as an assignment
     * system, so somebody can pass, and later lapse, a test nobody assigned
     * them. `people_assigned: 0` beside `people_due_for_retaking: 1` is the
     * honest shape of that, not a bug. Pins the BEHAVIOUR (already correct);
     * only the comment was wrong.
     */
    public function test_a_library_pass_can_lapse_with_nobody_assigned(): void
    {
        $tenant = $this->provisionTenant('Library-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Library Test');

        // No assignRow() at all — this is a library pass, never assigned.
        $this->aLapsedPass($tenant, $learner, $test, daysAgo: 1);

        $body = $this->signedInAs($monitor)->getJson('/api/v1/training/dashboard')->json();

        $row = collect($body['data'])->firstWhere('id', $test->id);
        $this->assertSame(0, $row['assigned'], 'Nobody assigned this test to this person.');
        $this->assertSame(1, $row['retaking'], 'Their library pass lapsed all the same.');

        $this->assertSame(0, $body['summary']['people_assigned']);
        $this->assertSame(1, $body['summary']['people_due_for_retaking']);
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

    /**
     * The person-reach case content scope cannot catch: an
     * ORGANIZATION-WIDE test is visible to every monitor, so
     * `InventoryVisibility` constrains nothing for it at all. It is still
     * `summary`'s job — via `ScopeReach::constrainToVisiblePeople` — to
     * never count a Northgate-only person's lapsed pass for a Riverside-only
     * monitor. Reproduces task-11 review Important 3, proven live: this
     * exact fixture returned `people_due_for_retaking: 1` before the fix.
     */
    public function test_the_summary_does_not_count_a_person_outside_reach_on_an_organization_wide_test(): void
    {
        $tenant = $this->provisionTenant('SummaryReach-'.Str::random(8));
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $riversideMonitor = $this->userWithRole($tenant, 'RiversideMonitor', ['training.monitor'], onlyAt: $riverside);
        $northgateLearner = $this->userWithRole($tenant, 'NorthgateLearner', ['training.view'], onlyAt: $northgate);

        $orgWideTest = $this->anOrgWideTest($tenant, 'Org Wide Ladder Test');
        $this->aLapsedPass($tenant, $northgateLearner, $orgWideTest, daysAgo: 1);

        $body = $this->signedInAs($riversideMonitor)->getJson('/api/v1/training/dashboard')->json();

        $titles = collect($body['data'])->pluck('title');
        $this->assertContains('Org Wide Ladder Test', $titles, 'The row itself is content-scope visible everywhere.');

        $this->assertSame(
            0,
            $body['summary']['people_due_for_retaking'],
            "A Northgate-only person's lapsed pass must not count for a Riverside-only monitor, even on an organization-wide test.",
        );

        $row = collect($body['data'])->firstWhere('title', 'Org Wide Ladder Test');
        $this->assertSame(0, $row['retaking'], 'The row itself must not count the out-of-reach person either.');
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

    /**
     * Critical 2 (task-11 review): the pane's own person reach, on an
     * ORGANIZATION-WIDE test — content scope (`findVisibleItem`) admits the
     * item as a row for every monitor, so the pane must filter its audience
     * by `ScopeReach::constrainToVisiblePeople`, not by the item's own
     * visibility. Proven live before the fix: this fixture's `/people`
     * response named the Northgate learner and their score to a
     * Riverside-only monitor.
     */
    public function test_the_people_pane_excludes_a_person_outside_reach_on_an_organization_wide_test(): void
    {
        $tenant = $this->provisionTenant('PaneReach-'.Str::random(8));
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $riversideMonitor = $this->userWithRole($tenant, 'RiversideMonitor', ['training.monitor'], onlyAt: $riverside);
        $northgateLearner = $this->userWithRole($tenant, 'NorthgateLearner', ['training.view'], onlyAt: $northgate);

        $orgWideTest = $this->anOrgWideTest($tenant, 'Org Wide Ladder Test');
        $this->aLapsedPass($tenant, $northgateLearner, $orgWideTest, daysAgo: 1);

        $response = $this->signedInAs($riversideMonitor)
            ->getJson("/api/v1/training/dashboard/{$orgWideTest->id}/people");

        $response->assertOk();

        $rows = collect($response->json('data'));
        $this->assertNull(
            $rows->firstWhere('user_id', $northgateLearner->id),
            'A Northgate-only learner must not appear to a Riverside-only monitor, even on an organization-wide test.',
        );
        $this->assertStringNotContainsString('NorthgateLearner', $response->getContent());
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

    /**
     * Critical 1 (task-11 review): the ONLY check `records` had on the
     * subject was `Rule::exists('users','id')->where('organization_id', …)`
     * — a TENANT check, not a reach check. On an ORGANIZATION-WIDE test
     * (visible to every monitor, so content scope constrains nothing) a
     * Riverside-only monitor could name a Northgate-only learner and read
     * their score. Proven live before the fix: this exact fixture returned
     * `status=200` with the learner's name and a 91-equivalent score.
     */
    public function test_records_refuses_a_person_outside_reach_on_an_organization_wide_test(): void
    {
        $tenant = $this->provisionTenant('RecordsReach-'.Str::random(8));
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $riversideMonitor = $this->userWithRole($tenant, 'RiversideMonitor', ['training.monitor'], onlyAt: $riverside);
        $northgateLearner = $this->userWithRole($tenant, 'NorthgateLearner', ['training.view'], onlyAt: $northgate);

        $orgWideTest = $this->anOrgWideTest($tenant, 'Org Wide Ladder Test');
        $this->aLapsedPass($tenant, $northgateLearner, $orgWideTest, daysAgo: 1);

        $response = $this->signedInAs($riversideMonitor)
            ->getJson("/api/v1/training/records?user={$northgateLearner->id}");

        $response->assertForbidden();
        $this->assertStringNotContainsString('NorthgateLearner', $response->getContent());
        $this->assertStringNotContainsString('Org Wide Ladder Test', $response->getContent());

        // A plain sentence — never a section number, a class name, or
        // "immutable" (task-11 constraints; §4's own house style).
        $message = $response->json('message');
        $this->assertIsString($message);
        $this->assertStringNotContainsStringIgnoringCase('§', $message);
        $this->assertStringNotContainsStringIgnoringCase('immutable', $message);
    }

    /**
     * A genuinely different ORGANIZATION, not a sibling franchise — the
     * tenant boundary `records` validates independently of person reach
     * (task-11 review, Important 4: "all 17 tests use a single tenant, so
     * even the organization boundary on records is unpinned").
     */
    public function test_records_refuses_a_user_from_a_different_organization(): void
    {
        $tenantA = $this->provisionTenant('OrgA-'.Str::random(8));
        $tenantB = $this->provisionTenant('OrgB-'.Str::random(8));

        $monitorA = $this->userWithRole($tenantA, 'Monitor', ['training.monitor']);
        $learnerB = $this->userWithRole($tenantB, 'Learner', ['training.view']);

        $this->signedInAs($monitorA)
            ->getJson("/api/v1/training/records?user={$learnerB->id}")
            ->assertStatus(422);
    }

    /**
     * Finding 8 (task-11 review): identity was disclosed independently of
     * whether there was anything to show it alongside. A monitor who DOES
     * reach this person, but for whom every guide/test row was filtered
     * out (nothing assigned, nothing attempted), must not learn the
     * person's name from an otherwise-empty response either.
     */
    public function test_records_omits_identity_when_there_is_nothing_to_show(): void
    {
        $tenant = $this->provisionTenant('RecordsEmpty-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        // Reachable, and a published item exists — but the learner has never
        // touched it: no assignment, no progress, no attempt.
        $this->aPublishedTest($tenant, 'Untouched Test');

        $body = $this->signedInAs($monitor)
            ->getJson("/api/v1/training/records?user={$learner->id}")->json();

        $this->assertSame([], $body['data']);
        $this->assertNull($body['user'], 'No content means no identity either — see [records]\'s finding-8 note.');
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
    // -- The whole history, not only current standing (owner, "all", 2026-09-09) --

    /**
     * Three attempts come back as three, newest first - and the row's own
     * `score`/`passed` still describe the LATEST one, because `state` is
     * built from current standing and the two must not disagree inside a
     * single row.
     */
    public function test_the_people_pane_carries_every_attempt_newest_first(): void
    {
        $tenant = $this->provisionTenant('History-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Ladders', validForDays: 365);

        foreach ([[30, 40, false], [20, 55, false], [10, 90, true]] as [$daysAgo, $score, $passed]) {
            $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => $score,
                'passed' => $passed,
                'taken_at' => now()->subDays($daysAgo),
                'expires_on' => $passed ? now()->addDays(300)->toDateString() : null,
            ]));
        }

        $row = collect($this->signedInAs($monitor)
            ->getJson("/api/v1/training/dashboard/{$test->id}/people")->json('data'))
            ->firstWhere('user_id', $learner->id);

        $this->assertNotNull($row);
        $this->assertCount(3, $row['attempts']);
        $this->assertSame([90, 55, 40], array_column($row['attempts'], 'score'));
        $this->assertSame([true, false, false], array_column($row['attempts'], 'passed'));

        // Current standing, unchanged and still the latest attempt.
        $this->assertSame(90, $row['score']);
        $this->assertTrue($row['passed']);
    }

    /**
     * The control that makes the test above mean something: a person with
     * ONE attempt gets a one-entry history, not an empty one and not a
     * missing key. Without this, an `attempts` that silently dropped
     * everything but multi-attempt users would pass.
     */
    public function test_a_single_attempt_is_a_history_of_one(): void
    {
        $tenant = $this->provisionTenant('History-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Ladders', validForDays: 365);

        $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 70,
            'passed' => true,
            'taken_at' => now()->subDay(),
            'expires_on' => now()->addDays(300)->toDateString(),
        ]));

        $row = collect($this->signedInAs($monitor)
            ->getJson("/api/v1/training/dashboard/{$test->id}/people")->json('data'))
            ->firstWhere('user_id', $learner->id);

        $this->assertCount(1, $row['attempts']);
        $this->assertSame(70, $row['attempts'][0]['score']);
    }

    /**
     * A GUIDE has no attempts to carry - its history is `last_screen` and
     * `completed_at`, which the pane already drew. The key must be absent
     * rather than an empty list, so the client can tell "a test nobody has
     * sat" from "not a test at all".
     */
    public function test_a_guide_row_carries_no_attempts_key(): void
    {
        $tenant = $this->provisionTenant('History-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $guide = $this->aPublishedGuide($tenant, 'Ladder Safety');
        $this->assignRow($tenant, $guide, null, $learner);

        $row = collect($this->signedInAs($monitor)
            ->getJson("/api/v1/training/dashboard/{$guide->id}/people")->json('data'))
            ->firstWhere('user_id', $learner->id);

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('attempts', $row);
    }

    /**
     * HR's read carries the same history, because it answers the SAME
     * question about the same person on the same day as the pane above. One
     * of them showing three attempts while the other shows one would be two
     * truths about one person.
     */
    public function test_the_hr_record_read_carries_the_history_too(): void
    {
        $tenant = $this->provisionTenant('History-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->aPublishedTest($tenant, 'Ladders', validForDays: 365);
        $this->assignRow($tenant, null, $test, $learner);

        foreach ([[20, 40, false], [10, 90, true]] as [$daysAgo, $score, $passed]) {
            $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => $score,
                'passed' => $passed,
                'taken_at' => now()->subDays($daysAgo),
                'expires_on' => $passed ? now()->addDays(300)->toDateString() : null,
            ]));
        }

        $row = collect($this->signedInAs($monitor)
            ->getJson("/api/v1/training/records?user={$learner->id}")->json('data'))
            ->firstWhere('id', $test->id);

        $this->assertNotNull($row);
        $this->assertSame([90, 40], array_column($row['attempts'], 'score'));
    }

    /**
     * The gap "all" exposed. HR's read decided a test earned a row by
     * asking `currentStandingQuery`, which is the latest PASSED attempt -
     * so somebody who has only ever FAILED an unassigned test had no row at
     * all, and they are exactly who a record card is wanted for.
     */
    public function test_a_person_who_only_ever_failed_still_gets_a_record_row(): void
    {
        $tenant = $this->provisionTenant('History-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        // Never assigned - taken from the library - and never passed.
        $test = $this->aPublishedTest($tenant, 'Ladders', validForDays: 365);

        $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [0, 0],
            'score' => 20,
            'passed' => false,
            'taken_at' => now()->subDay(),
            'expires_on' => null,
        ]));

        $body = $this->signedInAs($monitor)
            ->getJson("/api/v1/training/records?user={$learner->id}")->json();

        $row = collect($body['data'])->firstWhere('id', $test->id);

        $this->assertNotNull($row, 'A failed attempt is a training record.');
        $this->assertFalse($row['assigned']);
        $this->assertNull($row['passed']);
        $this->assertSame([20], array_column($row['attempts'], 'score'));
    }

    /**
     * The control for the test above, and the reason it is not simply "show
     * every test": a published test this person has never touched and was
     * never assigned still earns NO row. Without this, dropping the filter
     * entirely would pass.
     */
    public function test_an_untouched_unassigned_test_still_earns_no_record_row(): void
    {
        $tenant = $this->provisionTenant('History-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $untouched = $this->aPublishedTest($tenant, 'Never Sat', validForDays: 365);

        $body = $this->signedInAs($monitor)
            ->getJson("/api/v1/training/records?user={$learner->id}")->json();

        $this->assertNull(collect($body['data'])->firstWhere('id', $untouched->id));
    }

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

    /**
     * `company_id`/`franchise_id` both null — General/organization-wide, per
     * `InventoryVisibility`'s own docblock ("Organization rows (`company_id`
     * null) are the group register: anyone with reach in the tenant sees
     * them"). Visible to every monitor in the tenant regardless of
     * franchise, which is exactly the case content scope cannot narrow and
     * person reach has to.
     */
    private function anOrgWideTest(array $tenant, string $title = 'A Test'): TrainingTest
    {
        return $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null,
            'franchise_id' => null,
            'title' => $title,
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => 40,
            'valid_for_days' => 30,
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
