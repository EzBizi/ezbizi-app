<?php

namespace Tests\Feature\Training;

use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

        $this->completeThrough($learner, $guide, 3)
            ->assertOk();

        $row = $this->libraryEntry($learner, $guide->id);

        $this->assertSame(3, $row['last_screen']);
        $this->assertNull($row['completed_at'], 'Screen 3 of 8 is not the last screen.');
        $this->assertNotNull($row['last_read_at'], 'What a Continue card picks the latest guide by.');
    }

    public function test_a_library_guide_carries_completed_at_once_finished(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $guide = $this->aGuide($tenant, 'Ladder Safety', 8);

        $this->completeThrough($learner, $guide, 7)
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

        $this->completeThrough($learner, $touched, 1)
            ->assertOk();

        $row = $this->libraryEntry($learner, $untouched->id);

        $this->assertNull($row['last_screen']);
        $this->assertNull($row['completed_at']);
        $this->assertNull($row['last_read_at']);
    }

    /** One learner's reading position must never appear on a different learner's card. */
    public function test_one_learners_progress_never_appears_on_another_learners_card(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learnerA = $this->userWithRole($tenant, 'LearnerA', ['training.view'], onlyAt: $tenant['franchise']);
        $learnerB = $this->userWithRole($tenant, 'LearnerB', ['training.view'], onlyAt: $tenant['franchise']);
        $guide = $this->aGuide($tenant, 'Shared Guide', 4);

        $this->completeThrough($learnerA, $guide, 3)
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

        $this->inTenant($tenant['org'], fn () => TrainingAssignment::create([
            'guide_id' => $guide->id,
            'assignee_type' => TrainingAssignment::USER,
            'assignee_id' => $learner->id,
        ]));

        $this->completeThrough($learner, $guide, 2)
            ->assertOk();

        $row = $this->assignedEntry($learner, $guide->id);

        $this->assertSame(2, $row['last_screen']);
        $this->assertNull($row['completed_at']);
    }

    // ── attempts_taken: the count nothing else in the learner payload can
    //    give (task-13 review) ──────────────────────────────────────────
    //
    // `last_taken_at` only ever says "at least one, or none" — it cannot
    // tell one prior attempt from several. Under `max_attempts: 3` a
    // learner with three prior attempts from an EARLIER sitting reopens
    // the test fresh: the client has no session-local count either, so
    // without this field it has nothing to gate Submit on and offers a
    // control the server will 403. `attempts_taken` is the exact count,
    // batched the same way [lastAttempts] already is — see
    // [LearnController::attemptCounts].

    public function test_a_library_test_carries_the_exact_number_of_attempts_taken(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Three Sittings');

        $this->attempt($tenant, $learner, $test, score: 40, passed: false, takenAt: now()->subDays(30));
        $this->attempt($tenant, $learner, $test, score: 50, passed: false, takenAt: now()->subDays(20));
        $this->attempt($tenant, $learner, $test, score: 60, passed: true, takenAt: now()->subDays(10));

        $row = $this->libraryEntry($learner, $test->id);

        $this->assertSame(3, $row['attempts_taken']);
    }

    public function test_an_untaken_test_carries_zero_attempts_taken(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Never Sat');

        $row = $this->libraryEntry($learner, $test->id);

        $this->assertSame(0, $row['attempts_taken']);
    }

    /** Same batching proof [lastAttempts] already has, for the count read. */
    public function test_an_untaken_test_alongside_a_taken_one_carries_the_right_count_for_each(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $taken = $this->aTest($tenant, 'Taken Twice');
        $untaken = $this->aTest($tenant, 'Untaken Again');

        $this->attempt($tenant, $learner, $taken, score: 40, passed: false, takenAt: now()->subDays(2));
        $this->attempt($tenant, $learner, $taken, score: 90, passed: true, takenAt: now());

        $this->assertSame(2, $this->libraryEntry($learner, $taken->id)['attempts_taken']);
        $this->assertSame(0, $this->libraryEntry($learner, $untaken->id)['attempts_taken']);
    }

    public function test_show_test_carries_the_same_attempts_taken_count_as_the_list(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Two Sittings');

        $this->attempt($tenant, $learner, $test, score: 40, passed: false, takenAt: now()->subDays(5));
        $this->attempt($tenant, $learner, $test, score: 70, passed: true, takenAt: now()->subDays(1));

        $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$test->id}")
            ->assertOk()
            ->assertJsonPath('data.attempts_taken', 2);
    }

    public function test_show_test_carries_zero_attempts_taken_when_never_sat(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Show Never Sat');

        $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$test->id}")
            ->assertOk()
            ->assertJsonPath('data.attempts_taken', 0);
    }

    /** One learner's attempt count must never appear on another learner's card. */
    public function test_one_learners_attempts_taken_never_appears_on_another_learners_card(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learnerA = $this->userWithRole($tenant, 'LearnerA', ['training.view'], onlyAt: $tenant['franchise']);
        $learnerB = $this->userWithRole($tenant, 'LearnerB', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant, 'Shared Test');

        $this->attempt($tenant, $learnerA, $test, score: 40, passed: false, takenAt: now());

        $row = $this->libraryEntry($learnerB, $test->id);

        $this->assertSame(0, $row['attempts_taken'], "Learner B must not see Learner A's attempt count.");
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

    /**
     * Topics unlock in order, so reaching topic N means completing 0..N in
     * turn, as a learner would. Returns the response for N.
     */
    private function completeThrough(User $learner, TrainingGuide $guide, int $last): TestResponse
    {
        for ($i = 0; $i < $last; $i++) {
            $this->signedInAs($learner)->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => $i])->assertOk();
        }

        return $this->signedInAs($learner)->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => $last]);
    }
}
