<?php

namespace Tests\Feature\Training;

use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The ruling on Task 12's review: `/training/learn` was dropping progress
 * data it already read. `LearnController::index` already runs
 * `guideFinishedBy()`-shaped `training_progress` reads and a per-user
 * `training_attempts` query (`lapsedTestIds()`) on every load — the card
 * data the approved mockup (`docs/previews/training-layouts.html`, layout 1)
 * shows was never a new endpoint, only a field this controller was silently
 * throwing away. Closed by batching two more reads into the SAME collection
 * pass `index()` already makes: [guideProgress] and [lastAttempts] on
 * `LearnController`.
 *
 * ── Why "most recent attempt", not "current standing" ─────────────
 *
 * `LapsedPasses::currentStandingQuery` (what `lapsedTestIds()` reads) only
 * ever considers PASSED, non-superseded attempts — it answers "is this
 * person's pass still good". `last_score`/`last_passed`/`last_taken_at`
 * answer a different question a card needs regardless of outcome: "what did
 * this person do last". `test_a_library_test_shows_the_most_recent_attempt_even_when_it_failed_after_an_earlier_pass`
 * below is the one case that tells the two definitions apart — reusing
 * `currentStandingQuery` here would silently report the OLD passed attempt
 * instead of the newer failed one.
 */
class TrainingLearnProgressFieldsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── Guides: last_screen / completed_at ───────────────────────────

    public function test_a_library_guide_carries_this_learners_own_last_screen_while_in_progress(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $guide = $this->aGuide($tenant, 'Ladder Safety', 8);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 3])
            ->assertOk();

        $row = $this->libraryEntry($learner, $guide->id);

        $this->assertSame(3, $row['last_screen']);
        $this->assertNull($row['completed_at'], 'Screen 3 of 8 is not the last screen.');
    }

    public function test_a_library_guide_carries_completed_at_once_finished(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $guide = $this->aGuide($tenant, 'Ladder Safety', 8);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 7])
            ->assertOk();

        $row = $this->libraryEntry($learner, $guide->id);

        $this->assertSame(7, $row['last_screen']);
        $this->assertNotNull($row['completed_at']);
    }

    /**
     * Two guides in the same library read, only one of them touched — proves
     * [guideProgress] keys its batched read by `guide_id` correctly rather
     * than, say, handing every card the first row it happens to find.
     */
    public function test_an_untouched_guide_alongside_a_touched_one_carries_null_progress_fields(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $touched = $this->aGuide($tenant, 'Touched Guide', 4);
        $untouched = $this->aGuide($tenant, 'Untouched Guide', 4);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$touched->id}/seen", ['screen' => 1])
            ->assertOk();

        $row = $this->libraryEntry($learner, $untouched->id);

        $this->assertNull($row['last_screen']);
        $this->assertNull($row['completed_at']);
    }

    /** One learner's reading position must never appear on a different learner's card. */
    public function test_one_learners_progress_never_appears_on_another_learners_card(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learnerA = $this->userWithRole($tenant, 'LearnerA', ['training.view'], onlyAt: $tenant['franchise']);
        $learnerB = $this->userWithRole($tenant, 'LearnerB', ['training.view'], onlyAt: $tenant['franchise']);
        $guide = $this->aGuide($tenant, 'Shared Guide', 4);

        $this->signedInAs($learnerA)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 3])
            ->assertOk();

        $row = $this->libraryEntry($learnerB, $guide->id);

        $this->assertNull($row['last_screen'], "Learner B must not see Learner A's reading position.");
        $this->assertNull($row['completed_at']);
    }

    // ── Tests: last_score / last_passed / last_taken_at ──────────────

    public function test_an_untaken_test_carries_null_attempt_fields(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Flue Liner Report');

        $row = $this->libraryEntry($learner, $test->id);

        $this->assertNull($row['last_score']);
        $this->assertNull($row['last_passed']);
        $this->assertNull($row['last_taken_at']);
    }

    public function test_a_library_test_carries_its_one_attempts_score_and_outcome(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Customer Greeting');

        $this->attempt($tenant, $learner, $test, score: 90, passed: true, takenAt: now()->subDays(30));

        $row = $this->libraryEntry($learner, $test->id);

        $this->assertSame(90, $row['last_score']);
        $this->assertTrue($row['last_passed']);
        $this->assertNotNull($row['last_taken_at']);
    }

    /**
     * The case that tells [lastAttempts] apart from `LapsedPasses::currentStandingQuery`:
     * an EARLIER pass, then a LATER fail. `currentStandingQuery` would still
     * report the earlier pass (it only ever looks at passed attempts) —
     * this field must report the later, failed one, because it answers "what
     * did they do last", not "is their pass still good".
     */
    public function test_a_library_test_shows_the_most_recent_attempt_even_when_it_failed_after_an_earlier_pass(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Carbon Monoxide Awareness');

        $this->attempt($tenant, $learner, $test, score: 100, passed: true, takenAt: now()->subDays(60));
        $this->attempt($tenant, $learner, $test, score: 20, passed: false, takenAt: now()->subDays(1));

        $row = $this->libraryEntry($learner, $test->id);

        $this->assertSame(20, $row['last_score']);
        $this->assertFalse($row['last_passed']);
    }

    /** Same two-tests-one-learner batching proof as guides, for the attempt read. */
    public function test_an_untaken_test_alongside_a_taken_one_carries_null_attempt_fields(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $taken = $this->aTest($tenant, 'Taken Test');
        $untaken = $this->aTest($tenant, 'Untaken Test');

        $this->attempt($tenant, $learner, $taken, score: 80, passed: true, takenAt: now());

        $row = $this->libraryEntry($learner, $untaken->id);

        $this->assertNull($row['last_score']);
        $this->assertNull($row['last_passed']);
        $this->assertNull($row['last_taken_at']);
    }

    // ── The assigned shelf carries the same fields ────────────────────

    public function test_an_assigned_guide_carries_progress_fields_too(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $guide = $this->aGuide($tenant, 'Assigned Guide', 5);

        $this->inTenant($tenant['org'], fn () => \App\Models\TrainingAssignment::create([
            'guide_id' => $guide->id,
            'assignee_type' => \App\Models\TrainingAssignment::USER,
            'assignee_id' => $learner->id,
        ]));

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 2])
            ->assertOk();

        $row = $this->assignedEntry($learner, $guide->id);

        $this->assertSame(2, $row['last_screen']);
        $this->assertNull($row['completed_at']);
    }

    // ── showTest stays consistent with the list ───────────────────────

    public function test_show_test_carries_the_same_last_attempt_fields_as_the_list(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Reading a Flue Liner Report');

        $this->attempt($tenant, $learner, $test, score: 65, passed: false, takenAt: now()->subDays(2));

        $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$test->id}")
            ->assertOk()
            ->assertJsonPath('data.last_score', 65)
            ->assertJsonPath('data.last_passed', false)
            ->assertJsonPath('data.last_taken_at', fn ($v) => $v !== null);
    }

    public function test_show_test_carries_null_attempt_fields_when_never_taken(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Never Taken');

        $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$test->id}")
            ->assertOk()
            ->assertJsonPath('data.last_score', null)
            ->assertJsonPath('data.last_passed', null)
            ->assertJsonPath('data.last_taken_at', null);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function aGuide(array $tenant, string $title, int $screenCount): TrainingGuide
    {
        return $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => $title,
            'status' => TrainingGuide::PUBLISHED,
            'screens' => array_map(
                fn (int $n) => ['title' => "Screen {$n}", 'body' => 'Content.', 'media_kind' => 'none', 'media_url' => null],
                range(1, $screenCount),
            ),
        ]));
    }

    private function aTest(array $tenant, string $title): TrainingTest
    {
        return $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'guide_id' => null,
            'title' => $title,
            'status' => TrainingTest::PUBLISHED,
            'questions' => [],
            'requires_guide' => false,
            'pass_mark' => 60,
        ]));
    }

    /** A real attempt row, written directly — this file is about what the READ shows, not the grading path TrainingGradingTest already covers. */
    private function attempt(array $tenant, User $learner, TrainingTest $test, int $score, bool $passed, \DateTimeInterface $takenAt): TrainingAttempt
    {
        return $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [],
            'score' => $score,
            'passed' => $passed,
            'taken_at' => $takenAt,
        ]));
    }

    /** @return array<string, mixed> */
    private function libraryEntry(User $learner, string $id): array
    {
        $body = $this->signedInAs($learner)->getJson('/api/v1/training/learn')->assertOk()->json();

        return collect($body['library'])->firstWhere('id', $id)
            ?? $this->fail("No library entry with id {$id}.");
    }

    /** @return array<string, mixed> */
    private function assignedEntry(User $learner, string $id): array
    {
        $body = $this->signedInAs($learner)->getJson('/api/v1/training/learn')->assertOk()->json();

        return collect($body['assigned'])->firstWhere('id', $id)
            ?? $this->fail("No assigned entry with id {$id}.");
    }
}
