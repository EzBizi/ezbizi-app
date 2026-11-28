<?php

namespace Tests\Feature\Training;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\Notification;
use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingProgress;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Automations\AutomationEngine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Marking a submitted test (task-8 brief) — `POST
 * /training/learn/tests/{test}/attempts`.
 *
 * The gate (decision 1: `requires_guide` with a null `guide_id` is no gate at
 * all), the pass mark (the TEST's own, never a platform constant), the retake
 * cap (a race closed the same way task 7 closed `completed_at`'s), and the
 * expiry date a pass carries when the test's `valid_for_days` says so.
 *
 * ── Grading never trusts the wire ─────────────────────────────────
 *
 * `TestGrader::grade` marks against the STORED test — the one the server
 * fetched through the same InventoryVisibility/published filter every other
 * learner read in this module uses — never against anything reconstructed
 * from the client's answers. `LearnerView::stripAnswerKey` already means
 * there is no answer key on the wire to mark against even if somebody tried.
 *
 * ── The retake cap is a race, not a read-then-write check ─────────
 *
 * Two concurrent submissions both counting the same existing attempts and
 * both seeing room is the exact shape `StaffAccountsController::update`'s
 * docblock names: "Read-then-write with nothing in between is not a
 * uniqueness rule, it is a race." Attempts are INSERTS, not upserts, so
 * there is no existing attempt row a first submission could lock the way
 * task 7 locked `training_progress` after `insertOrIgnore`. What is locked
 * instead is the TEST itself — the one row every submission against it
 * already reads. Lock the parent row every writer already reads, rather than
 * a membership row that might not exist yet. See `test_two_concurrent_submissions_for_the_last_retake_slot_admit_exactly_one`.
 */
class TrainingGradingTest extends TestCase
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

    // ── The brief's own four cases ────────────────────────────────

    public function test_a_gated_test_refuses_until_the_guide_is_finished(): void
    {
        [$learner, , $test] = $this->aGatedTest();

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'guide'));
    }

    /**
     * The other half of the gate — review round 1 caught that nothing built
     * a genuinely FINISHED `TrainingProgress` row anywhere in this file, so
     * `test_a_gated_test_refuses_until_the_guide_is_finished` passed for the
     * wrong reason: that learner has no progress row at all, and would have
     * been refused identically by code that let nobody through, ever — the
     * module's own no-dead-ends rule violated exactly. This test writes a
     * real completed row and asserts the gate opens; together with the
     * refusal test above, the two now differ by the one fact that actually
     * matters (`completed_at` set or not), which is what makes inverting the
     * `whereNotNull('completed_at')` check in `guideFinishedBy` show up as a
     * failure — see this class's own mutation check in the task report.
     */
    public function test_a_gated_test_is_takeable_once_the_guide_is_finished(): void
    {
        [$learner, $guide, $test, $tenant] = $this->aGatedTest();

        $this->inTenant($tenant['org'], fn () => TrainingProgress::create([
            'user_id' => $learner->id,
            'guide_id' => $guide->id,
            'last_screen' => 0,
            'completed_at' => now(),
        ]));

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()
            ->assertJsonPath('passed', true);
    }

    public function test_the_pass_mark_is_the_tests_own_and_not_a_platform_constant(): void
    {
        // Two questions, one right => 50%. Pass mark 40 passes; pass mark 60 does not.
        [$learner, $lenient] = $this->aTwoQuestionTest(passMark: 40);
        [$other, $strict] = $this->aTwoQuestionTest(passMark: 60);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$lenient->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', true)->assertJsonPath('score', 50);

        $this->signedInAs($other)
            ->postJson("/api/v1/training/learn/tests/{$strict->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', false)->assertJsonPath('score', 50);
    }

    public function test_the_retake_cap_is_enforced_and_null_means_unlimited(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 100, maxAttempts: 2);

        $this->signedInAs($learner)->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])->assertOk();
        $this->signedInAs($learner)->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])->assertOk();
        $this->signedInAs($learner)->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '2'));
    }

    public function test_a_pass_on_an_expiring_test_carries_the_date_it_runs_out(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 40, validForDays: 365);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()
            ->assertJsonPath('expires_on', now()->addDays(365)->toDateString());
    }

    // ── Required beyond the brief's four ──────────────────────────

    public function test_null_max_attempts_means_unlimited_retakes(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 100, maxAttempts: null);

        for ($i = 0; $i < 5; $i++) {
            $this->signedInAs($learner)
                ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
                ->assertOk();
        }

        $this->inTenant($tenant['org'], function () use ($test, $learner) {
            $this->assertSame(5, TrainingAttempt::query()
                ->where('user_id', $learner->id)->where('test_id', $test->id)->count());
        });
    }

    // ── Fifth option: count_attempts_after_pass (fifth-option brief, 2026-09-09) ──
    //
    // Default false: a retake sat while an existing pass is still valid does
    // not spend a `max_attempts` slot. The owner's own framing — the cap is
    // "meant for failures" — is why a passer improving their score is
    // exempt but a failer never is, and why a LAPSED pass stops sheltering
    // retakes the moment it lapses (see the last two tests below).

    public function test_a_passer_retaking_does_not_consume_the_cap_when_the_option_is_off(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 1, countAttemptsAfterPass: false);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', true);

        // A second slot never existed (max_attempts: 1) — this only succeeds
        // if the first, already-passed attempt is excluded from the count.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', true);

        // And a third, to show it is genuinely uncapped while the pass
        // holds — not merely one grace attempt.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', true);
    }

    public function test_a_passer_retaking_does_consume_the_cap_when_the_option_is_on(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 1, countAttemptsAfterPass: true);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', true);

        // Today's strict behaviour, preserved exactly: the pass itself
        // already spent the one slot.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '1'));
    }

    public function test_a_failers_attempts_always_count_even_with_the_option_off(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 100, maxAttempts: 1, countAttemptsAfterPass: false);

        // [0, 0] never passes against a 100 pass mark (twoQuestions' own
        // fixture) — there is no pass here to shelter behind, ever.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk()->assertJsonPath('passed', false);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '1'));
    }

    /**
     * The permanent-lockout defect this task closes (owner's ruling,
     * 2026-09-09, one step past the fifth-option brief above): with
     * `max_attempts: 1`, the PASS itself used to spend the one slot in
     * `walkCountingTowardCap` — nothing precedes the first attempt to
     * shelter it. The end-of-walk override (`$currentlyValid ? 0 :
     * $counted`) masked that miscount for as long as the pass stayed
     * current, but the moment it lapsed the raw count (1) was exposed
     * against `max_attempts: 1` and the learner could never sit the test
     * again — a genuine pass, on record, permanently unable to
     * re-certify. Seeded the same way the other lapsed-pass tests in this
     * file are (real elapsed time is not reproducible through the HTTP
     * API): the pass is already 20 days expired before this test's one
     * live submission — the retake — is sent.
     */
    public function test_a_lapsed_pass_never_locks_a_learner_out_of_retaking_forever(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 1, validForDays: 10, countAttemptsAfterPass: false);

        $this->inTenant($tenant['org'], function () use ($learner, $test) {
            // Passed 30 days ago, good for 10 — lapsed on day -20.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 50,
                'passed' => true,
                'taken_at' => now()->subDays(30),
                'expires_on' => now()->subDays(20)->toDateString(),
            ]);
        });

        // The pass never counted against max_attempts: 1 in the first
        // place, so the retake must be admitted — not permanently refused.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()
            ->assertJsonPath('passed', true);
    }

    /**
     * The other half of "the cap should count failures" (owner's ruling):
     * a FAILURE taken while an earlier pass was still valid must never
     * spend a `max_attempts` slot EITHER — sheltering only the pass itself
     * and not the retakes taken under it would still lock a learner out
     * once that pass lapses, the identical permanent dead end one layer
     * over ("failures only" reintroduces the lockout on its own, argued in
     * the task report). Seeded: a pass now 10 days expired, and a failing
     * retake sat 30 days ago, back when that pass was still 20 days from
     * expiring.
     */
    public function test_a_failure_taken_while_holding_a_valid_pass_never_counts_even_after_the_pass_later_lapses(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 1, validForDays: 30, countAttemptsAfterPass: false);

        $this->inTenant($tenant['org'], function () use ($learner, $test) {
            // Passed 40 days ago, good for 30 — lapsed 10 days ago.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 50,
                'passed' => true,
                'taken_at' => now()->subDays(40),
                'expires_on' => now()->subDays(10)->toDateString(),
            ]);

            // Sat 30 days ago — 10 days into the pass above, while it was
            // still good — and failed.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [0, 0],
                'score' => 0,
                'passed' => false,
                'taken_at' => now()->subDays(30),
                'expires_on' => null,
            ]);
        });

        // Neither seeded attempt counts — the pass never does, and the
        // failure was sheltered by it at the time it was sat — so this live
        // retake, sat after the pass has since lapsed, is still admitted.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk();
    }

    /**
     * Pins the sheltering exclusion live, not seeded: failing retakes sat
     * while today's pass is still good must not spend `max_attempts` slots.
     *
     * TWO failing retakes, and the second one is the whole test. With ONE,
     * this asserts nothing about the shelter at all: the per-cycle reset
     * (`LapsedPasses::walkCountingTowardCap`) zeroes the count at the pass,
     * so at the first retake's own submit the count is 0 whether or not
     * `$stillValid` sheltered anything, and every mutation of the shelter
     * leaves it green — measured, 2026-09-09, which is why the second
     * retake is here. At the SECOND retake the two readings finally differ:
     * sheltered, the first failure never incremented and this is admitted;
     * unsheltered, it incremented to 1, `max_attempts: 1` is reached, and
     * this is refused.
     */
    public function test_a_failure_taken_while_a_pass_is_still_valid_does_not_spend_a_slot(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 1, validForDays: 365, countAttemptsAfterPass: false);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', true);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk()->assertJsonPath('passed', false);

        // The assertion that actually needs the shelter: the pass is still
        // good, so the failure above spent nothing and this one is admitted
        // too, against `max_attempts: 1`.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk()->assertJsonPath('passed', false);
    }

    /**
     * The expired-pass decision, argued in the task report: once the
     * sheltering pass itself has lapsed, an attempt sat after it counts
     * normally again — "held a VALID, unexpired pass" is no longer true, so
     * the exemption this option grants no longer applies. Seeded directly
     * (`TrainingExpiryTest::aPassedAttemptExpiring`'s own technique) because
     * simulating elapsed real time through the HTTP API is not possible:
     * attempt 1 is a genuine pass whose `expires_on` already fell in the
     * past BEFORE attempt 2 was ever sat, and attempt 2 (seeded, not a real
     * submission) sits far enough after that lapse to be unambiguous. The
     * PASS itself never counts (owner's ruling, 2026-09-09 — the
     * permanent-lockout defect the two tests above target), so it takes
     * the seeded post-lapse failure PLUS one live failing retake to
     * actually reach `max_attempts: 2` here.
     */
    public function test_an_attempt_after_the_sheltering_pass_has_expired_counts_toward_the_cap(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 2, countAttemptsAfterPass: false);

        $this->inTenant($tenant['org'], function () use ($learner, $test) {
            // Passed 30 days ago, good for 10 days — lapsed on day -20.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 50,
                'passed' => true,
                'taken_at' => now()->subDays(30),
                'expires_on' => now()->subDays(20)->toDateString(),
            ]);

            // Sat on day -5 — 15 days after the pass above already lapsed,
            // so this one counts on its own account, not sheltered by it.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [0, 0],
                'score' => 0,
                'passed' => false,
                'taken_at' => now()->subDays(5),
                'expires_on' => null,
            ]);
        });

        // The pass does not count (0) — only the post-lapse failure does
        // (1 of 2) — so this live retake, whatever its outcome, is still
        // admitted.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk()->assertJsonPath('passed', false);

        // Two genuine failures are now on record (the seeded one and the
        // live one above) — max_attempts: 2 is spent.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '2'));
    }

    /**
     * The mirror of the test above: the same shape, but the sheltering pass
     * is STILL valid when the second attempt is sat. Neither seeded attempt
     * counts — the pass never does (owner's ruling, 2026-09-09), and the
     * failure sat under it is sheltered too — so a third submission (this
     * one, live) still has room against `max_attempts: 2`.
     */
    public function test_an_attempt_taken_while_the_sheltering_pass_is_still_valid_is_excluded_even_seeded(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 2, countAttemptsAfterPass: false);

        $this->inTenant($tenant['org'], function () use ($learner, $test) {
            // Passed 5 days ago, good for 30 days — still valid today.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 50,
                'passed' => true,
                'taken_at' => now()->subDays(5),
                'expires_on' => now()->addDays(25)->toDateString(),
            ]);

            // Sat one day later, while that pass is still good.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [0, 0],
                'score' => 0,
                'passed' => false,
                'taken_at' => now()->subDays(4),
                'expires_on' => null,
            ]);
        });

        // Neither seeded attempt counts — nothing has been spent — so this
        // live submission is admitted.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk();
    }

    /**
     * The exact boundary the two tests above straddle but never sit on: a
     * retake taken on the SAME calendar date the sheltering pass's own
     * `expires_on` names. [walkCountingTowardCap]'s `>` agrees with
     * `currentlyLapsedQuery`'s `<= today` — a pass is already lapsed ON
     * its expiry date, not the day after — so this retake is NOT sheltered
     * and counts toward the cap, same as the day-91 case the review finding
     * describes for an author reading the option's own label. The pass
     * itself still never counts (owner's ruling, 2026-09-09), so it takes
     * the boundary-day failure PLUS one live failing retake to reach
     * `max_attempts: 2`.
     */
    public function test_an_attempt_taken_on_the_exact_expiry_date_counts_toward_the_cap(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 2, countAttemptsAfterPass: false);

        $this->inTenant($tenant['org'], function () use ($learner, $test) {
            // Passed 10 days ago, expiring today — "today" is the boundary.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 50,
                'passed' => true,
                'taken_at' => now()->subDays(10),
                'expires_on' => now()->toDateString(),
            ]);

            // Sat today — the exact same calendar date as the expiry above,
            // not before it and not after it.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [0, 0],
                'score' => 0,
                'passed' => false,
                'taken_at' => now(),
                'expires_on' => null,
            ]);
        });

        // The pass does not count (0) — only the boundary-day failure does
        // (1 of 2) — so this live retake, whatever its outcome, is still
        // admitted.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk()->assertJsonPath('passed', false);

        // Two genuine failures are now on record — max_attempts: 2 is
        // spent.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '2'));
    }

    // ── The per-cycle ruling (owner, 2026-09-09, one step past the
    // failures-only ruling above): counting starts again at each landed
    // pass — a failure from a cycle the learner has since closed by
    // re-certifying must never be held against a LATER cycle's own
    // `max_attempts`. A 128-scenario search proved the failures-only fix
    // above still had a lifetime lockout: a lifetime SUM of every
    // unsheltered failure ever taken, however many closed, superseded
    // cycles it spans, eventually reaches `max_attempts` and refuses
    // forever, even though the learner may be only one failure into a
    // brand new cycle. `LapsedPasses::walkCountingTowardCap` now resets
    // `$counted` to 0 at every landed pass, exactly where `$holding`/
    // `$expiresOn` already reset — see that method's own docblock.

    /**
     * The exact defect the per-cycle ruling closes: a failure sat BEFORE
     * this person had ever passed — a closed cycle on its own, one
     * `max_attempts: 2` would not yet refuse anything for — must not be
     * resurrected against a LATER, already-re-certified cycle's own budget.
     *
     * Seeded: one genuine unsheltered failure, then a pass that has since
     * lapsed (closing that cycle). The first live retake is admitted under
     * EITHER a lifetime or a per-cycle reading (the running sum is only 1
     * either way). The SECOND live retake is where the two readings
     * disagree: a lifetime sum sees 2 failures total (the closed-cycle one
     * plus this new cycle's own) and wrongly refuses at `max_attempts: 2`
     * — the exact permanent lockout this task closes, reproduced against
     * the UNFIXED code as this test's own RED run. Per-cycle sees only 1
     * (the pre-pass failure belongs to a closed cycle), so it is still
     * admitted. A genuine THIRD failure in this SAME (still open, no new
     * pass since) cycle finally reaches `max_attempts: 2` counted since the
     * pass — the cap still works, it is just scoped to the cycle now.
     */
    public function test_a_failure_from_a_closed_cycle_never_counts_against_a_later_cycle(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 2, validForDays: 10, countAttemptsAfterPass: false);

        $this->inTenant($tenant['org'], function () use ($learner, $test) {
            // A genuine failure, before this person had ever passed —
            // closed the moment the pass below lands.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [0, 0],
                'score' => 0,
                'passed' => false,
                'taken_at' => now()->subDays(40),
                'expires_on' => null,
            ]);

            // Passed 35 days ago, good for 10 days — lapsed 25 days ago.
            // This pass closes the cycle the failure above belonged to.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 50,
                'passed' => true,
                'taken_at' => now()->subDays(35),
                'expires_on' => now()->subDays(25)->toDateString(),
            ]);
        });

        // First live retake: a genuine failure in the NEW cycle (the pass
        // has since lapsed). Admitted under either reading.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk()->assertJsonPath('passed', false);

        // Second live retake: lifetime and per-cycle disagree here. Still
        // admitted under per-cycle — only 1 failure has happened since the
        // learner's own most recent pass.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk()->assertJsonPath('passed', false);

        // A third failure in the same open cycle finally spends
        // `max_attempts: 2` counted since the pass.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '2'));
    }

    /**
     * The override at the end of `walkCountingTowardCap`
     * (`return $currentlyValid ? 0 : $counted`) has its own load-bearing
     * case, named in its own docblock: an author who lowers `max_attempts`
     * AFTER a learner already accumulated failures, while that learner
     * currently holds a still-valid pass. Seeded directly (accumulating
     * real failures through the HTTP API one at a time is not the point —
     * the point is the STATE: several failures on record, `max_attempts`
     * now lower than when they were taken, and a currently-valid pass held
     * since).
     *
     * Because the per-cycle reset above ALSO zeroes `$counted` at the same
     * pass that sets `$holding`, `$counted` is already 0 by the time this
     * walk reaches its own end whenever `$holding` is still true — the
     * override's `0` and the raw `$counted` now agree in every case a
     * mutation test can reach; see this file's own report for the measured
     * result of mutating the override away after this task's fix. This
     * test still pins the OBSERVABLE guarantee — a learner holding a
     * currently-valid pass is never refused, whatever their failure history
     * — regardless of which line in the walk is the one currently
     * providing it.
     */
    public function test_a_learner_holding_a_valid_pass_is_never_refused_even_with_failures_on_record(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40, maxAttempts: 2, validForDays: 30, countAttemptsAfterPass: false);

        $this->inTenant($tenant['org'], function () use ($learner, $test) {
            // Three genuine failures, before this person had ever passed —
            // more than today's max_attempts: 2 would allow, on their own.
            foreach ([40, 39, 38] as $daysAgo) {
                TrainingAttempt::create([
                    'user_id' => $learner->id,
                    'test_id' => $test->id,
                    'answers' => [0, 0],
                    'score' => 0,
                    'passed' => false,
                    'taken_at' => now()->subDays($daysAgo),
                    'expires_on' => null,
                ]);
            }

            // Passed 10 days ago, good for 30 — still valid today.
            TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 50,
                'passed' => true,
                'taken_at' => now()->subDays(10),
                'expires_on' => now()->addDays(20)->toDateString(),
            ]);
        });

        // Currently holding a valid pass — admitted regardless of the three
        // failures on record from before it.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk();
    }

    public function test_null_max_attempts_means_unlimited_retakes_with_the_option_on_too(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 100, maxAttempts: null, countAttemptsAfterPass: true);

        for ($i = 0; $i < 5; $i++) {
            $this->signedInAs($learner)
                ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
                ->assertOk();
        }
    }

    public function test_a_test_with_no_valid_for_days_never_carries_an_expiry(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 40, validForDays: null);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('expires_on', null);
    }

    public function test_a_fail_on_an_expiring_test_carries_no_expiry(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 100, validForDays: 365);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('expires_on', null);
    }

    /**
     * An answer naming a choice index that does not exist on the question
     * scores as wrong, not as a fatal error and not as a free point —
     * `$question['choices'][$chosen]['correct'] ?? false` in TestGrader
     * falls through the missing array offset to `false` via the null
     * coalescing operator. Not pinned anywhere else: every other fixture in
     * this file submits an index that genuinely exists on the question.
     */
    public function test_an_out_of_range_answer_index_scores_as_wrong_not_a_crash_or_a_point(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 40);

        // Both questions here have exactly two choices, indices 0 and 1.
        // Index 5 exists on neither.
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [5, 0]])
            ->assertOk()
            ->assertJsonPath('score', 0)
            ->assertJsonPath('passed', false);
    }

    /**
     * More answers than the test has questions — the grading loop is driven
     * by `$test->questions`, never by `count($answers)`, so trailing entries
     * with no matching question are simply never read rather than causing
     * an error or shifting the score.
     */
    public function test_more_answers_than_questions_are_silently_ignored(): void
    {
        [$learner, $test] = $this->aTwoQuestionTest(passMark: 40);

        // Two real questions, then three extra entries with nothing to
        // match them — the score must come out identical to submitting
        // just [1, 0] (one of two right, 50%).
        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0, 1, 1, 1]])
            ->assertOk()
            ->assertJsonPath('score', 50)
            ->assertJsonPath('passed', true);
    }

    /** Decision 3: zero questions scores 0 and fails — never a trivial 100%. */
    public function test_an_empty_test_is_unpassable_not_trivially_passed(): void
    {
        $tenant = $this->provisionTenant('Empty-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        // pass_mark 0 deliberately: the special case must hold regardless of
        // how lenient the mark is, not merely because 0 < any positive mark.
        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => 'Empty Test',
            'status' => TrainingTest::PUBLISHED,
            'questions' => [],
            'requires_guide' => false,
            'pass_mark' => 0,
        ]));

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => []])
            ->assertOk()
            ->assertJsonPath('score', 0)
            ->assertJsonPath('passed', false);
    }

    /**
     * Decision 1: `requires_guide: true` with `guide_id: null` is no gate at
     * all. `training_tests.guide_id` is `nullOnDelete`, so deleting the guide
     * reaches this test with no API call of its own — the house rule is no
     * dead ends, so this must stay takeable.
     */
    public function test_a_test_gated_by_a_since_deleted_guide_is_takeable(): void
    {
        $tenant = $this->provisionTenant('Ungated-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->inTenant($tenant['org'], function () use ($tenant) {
            $guide = TrainingGuide::create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Doomed Guide',
                'status' => TrainingGuide::PUBLISHED,
                'screens' => $this->oneScreen(),
            ]);

            $test = TrainingTest::create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'guide_id' => $guide->id,
                'title' => 'Orphaned Gate',
                'status' => TrainingTest::PUBLISHED,
                'questions' => $this->twoQuestions(),
                'requires_guide' => true,
                'pass_mark' => 40,
            ]);

            $guide->delete();

            return $test->fresh();
        });

        $this->assertNull($test->guide_id, 'The fixture did not actually reach the ungated shape.');

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()
            ->assertJsonPath('passed', true);
    }

    public function test_a_learner_cannot_submit_against_a_siblings_test(): void
    {
        $tenant = $this->provisionTenant('Sibling-'.Str::random(8));
        $northgate = $this->extraFranchise($tenant, 'Northgate');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $sibling = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $northgate->id,
            'title' => 'Northgate only',
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => 40,
        ]));

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$sibling->id}/attempts", ['answers' => [1, 0]])
            ->assertNotFound();

        $this->inTenant($tenant['org'], function () use ($sibling, $learner) {
            $this->assertDatabaseMissing('training_attempts', [
                'user_id' => $learner->id, 'test_id' => $sibling->id,
            ]);
        });
    }

    public function test_a_learner_cannot_submit_against_a_draft_test(): void
    {
        $tenant = $this->provisionTenant('Draft-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $draft = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Unfinished Test',
            'status' => TrainingTest::DRAFT,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => 40,
        ]));

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$draft->id}/attempts", ['answers' => [1, 0]])
            ->assertNotFound();

        $this->inTenant($tenant['org'], function () use ($draft, $learner) {
            $this->assertDatabaseMissing('training_attempts', [
                'user_id' => $learner->id, 'test_id' => $draft->id,
            ]);
        });
    }

    public function test_submitting_without_training_view_is_refused_with_a_reason(): void
    {
        [, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40);
        $noAccess = $this->userWithRole($tenant, 'NoAccess', []);

        $message = $this->signedInAs($noAccess)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('permission', $message);
        $this->assertStringNotContainsString('§', $message);
        $this->assertStringNotContainsString('LearnController', $message);
        $this->assertStringNotContainsString('immutable', $message);
    }

    // ── The outbox, not a dispatch ─────────────────────────────────

    public function test_passing_inserts_a_training_passed_outbox_row_and_dispatches_nothing(): void
    {
        Bus::fake();

        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk();

        Bus::assertNothingDispatched();

        $this->inTenant($tenant['org'], function () use ($test, $learner) {
            $attempt = TrainingAttempt::query()
                ->where('user_id', $learner->id)->where('test_id', $test->id)->firstOrFail();

            $event = AutomationEvent::query()->where('event_key', 'training.passed')->first();

            $this->assertNotNull($event, 'A passing attempt must insert a training.passed outbox row.');
            $this->assertSame(TrainingAttempt::class, $event->subject_type);
            $this->assertSame($attempt->id, $event->subject_id);
            $this->assertSame($learner->id, $event->actor_id);
            $this->assertNull($event->delivered_at, "Inserted, not delivered — delivery is the sweep's job.");
            $this->assertNull(
                AutomationEvent::query()->where('event_key', 'training.failed')->first(),
                'A passing attempt must not also raise training.failed.',
            );
        });
    }

    public function test_a_failing_attempt_inserts_training_failed_and_not_training_passed(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 100);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', false);

        $this->inTenant($tenant['org'], function () use ($test, $learner) {
            $this->assertNotNull(AutomationEvent::query()->where('event_key', 'training.failed')->first());
            $this->assertNull(AutomationEvent::query()->where('event_key', 'training.passed')->first());
        });
    }

    // ── The event's automation scope is the LEARNER'S place ────────

    public function test_automation_scope_resolves_to_the_learners_own_assignment(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($test, $learner, $tenant) {
            $attempt = TrainingAttempt::query()
                ->where('user_id', $learner->id)->where('test_id', $test->id)->firstOrFail();

            $this->assertSame(['franchise', $tenant['franchise']->id], $attempt->automationScope());
        });
    }

    /**
     * Coherence audit finding 6's `oldest()->oldest('id')` fix
     * (`TrainingAttempt::automationScope`'s own docblock) had a test for
     * `TrainingProgress` (`TrainingProgressTest::
     * test_automation_scope_is_deterministic_when_the_learner_holds_two_assignments`)
     * but not for this class, though the identical ordering lives here
     * too. Same shape: the learner's Riverside assignment (from
     * `aTwoQuestionTest`'s own `userWithRole`) is backdated older than a
     * second, Northgate one, and the OLDER row must win — asserted against
     * Riverside specifically so an unordered `first()` regressing back in
     * would not pass by coincidence.
     */
    public function test_automation_scope_is_deterministic_when_the_learner_holds_two_assignments(): void
    {
        [$learner, $test, $tenant] = $this->aTwoQuestionTest(passMark: 40);
        $riverside = $tenant['franchise'];

        $this->inTenant($tenant['org'], function () use ($tenant, $learner) {
            $northgate = \App\Models\Franchise::create(['company_id' => $tenant['company']->id, 'name' => 'Northgate']);

            $learner->assignments()->first()->forceFill(['created_at' => now()->subMinute()])->save();

            \App\Models\Assignment::create([
                'organization_id' => $tenant['org']->id, 'user_id' => $learner->id,
                'assigned_franchise_id' => $northgate->id,
            ]);
        });

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($test, $learner, $riverside) {
            $attempt = TrainingAttempt::query()
                ->where('user_id', $learner->id)->where('test_id', $test->id)->firstOrFail();

            $this->assertSame(
                ['franchise', $riverside->id],
                $attempt->automationScope(),
                'The OLDER assignment (Riverside) must win, deterministically, over the newer one (Northgate).',
            );
        });
    }

    /**
     * The behavioral test above proves the CHOSEN row is deterministic,
     * but with the older row also the FIRST-created one, MySQL can return
     * it in creation order often enough that the value assertion passes
     * by coincidence even with no `ORDER BY` at all —
     * `TrainingProgressTest::test_automation_scope_query_carries_an_explicit_order_by`'s
     * own docblock names the identical trap. This asserts the `ORDER BY`
     * clause itself is on the query, which cannot pass by coincidence the
     * way a value assertion can.
     */
    public function test_automation_scope_query_carries_an_explicit_order_by(): void
    {
        $tenant = $this->provisionTenant('Order-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Order Test', 'status' => TrainingTest::PUBLISHED,
            'questions' => [], 'requires_guide' => false, 'pass_mark' => 80,
        ]));

        $attempt = $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id, 'test_id' => $test->id,
            'answers' => [], 'score' => 0, 'passed' => false, 'taken_at' => now(),
        ]));

        $this->inTenant($tenant['org'], function () use ($attempt) {
            DB::enableQueryLog();
            $attempt->automationScope();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            $assignmentsQuery = collect($log)->first(
                fn (array $entry) => str_contains($entry['query'], 'from `assignments`')
                    || str_contains($entry['query'], 'from "assignments"'),
            );

            $this->assertNotNull($assignmentsQuery, 'automationScope() did not query assignments at all.');
            $this->assertStringContainsStringIgnoringCase('order by', $assignmentsQuery['query']);
        });
    }

    /**
     * Proving `automationScope()` NARROWS the audience, not merely that it is
     * present and returns a plausible-looking value in isolation.
     *
     * The rule is deliberately organization-wide (`scope_type`/`scope_id`
     * both null) so it matches regardless of where the event happened — what
     * is under test is `NotificationDispatcher::audienceFor`'s own reading of
     * `CarriesAutomationScope`, not `AutomationEngine`'s rule-scope matching.
     *
     * Both assertions matter together. A subject with NO `CarriesAutomationScope`
     * at all falls through to `audienceFor`'s column-sniff branch, which finds
     * neither `franchise_id` nor `owning_franchise_id` on a `TrainingAttempt`
     * and returns EVERY active user — so `$outsider` would wrongly appear. A
     * stub that always returned `[null, null]` would exclude the outsider too
     * (organization-level reach excludes a franchise-only user either way),
     * but would ALSO wrongly exclude `$coworker` — a real coworker at the
     * learner's own franchise, who has to be told for the rule to mean
     * anything. Only a correct, franchise-specific resolution passes both.
     */
    public function test_automation_scope_narrows_the_audience_not_merely_present(): void
    {
        $tenant = $this->provisionTenant('Narrow-'.Str::random(8));
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $coworker = $this->userWithRole($tenant, 'Coworker', [], onlyAt: $tenant['franchise']);
        $outsider = $this->userWithRole($tenant, 'Outsider', [], onlyAt: $northgate);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => 'Narrowing Test',
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => 40,
        ]));

        $this->rule($tenant, 'training.passed', null, null, actions: [
            ['type' => 'notify', 'message' => 'Somebody passed a test.'],
        ]);

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
            ->assertOk()->assertJsonPath('passed', true);

        $this->drain($tenant);

        $told = $this->inTenant($tenant['org'], fn () => Notification::query()
            ->where('source', 'automation')->pluck('user_id')->all());

        $this->assertNotEmpty($told, 'Nobody was told at all, so this proves nothing.');

        $this->assertContains(
            $coworker->id,
            $told,
            "The learner's own franchise coworker was not told — the scope did not reach "
                .'the right place, which a [null, null] stub would also produce.',
        );

        $this->assertNotContains(
            $outsider->id,
            $told,
            "Somebody at another franchise was told a named learner's test score — the leak "
                .'this method exists to close.',
        );
    }

    // ── The real race, not the sequential one ───────────────────────

    /**
     * Two SEQUENTIAL calls in `test_the_retake_cap_is_enforced_and_null_means_unlimited`
     * prove the ordinary case. It cannot prove the race a review found: two
     * requests genuinely overlapping, both counting the same existing attempts
     * before either has inserted — because nothing in that test ever holds a
     * transaction open while a second one starts.
     *
     * This test does that for real: two separate PHP processes, two separate
     * database connections, synchronized so the second one's `lockForUpdate()`
     * is provably still blocked on the first when it starts. Same shape as
     * `TrainingProgressTest::test_two_concurrent_finishes_of_the_same_guide_raise_the_event_exactly_once`
     * and `TaskConcurrencyTest`'s race workers, adapted because `submit()` has
     * no existing attempt row to lock (attempts are inserts, not upserts) — so
     * `submit_hold` pre-acquires the TEST row's lock instead, the one row every
     * submission against it already reads, and holds it across the pause.
     *
     * One attempt already exists (cap 2, one slot left) so the two racing
     * requests are contending for the LAST remaining slot — the shape that
     * actually catches an off-by-one in the count check, not merely two
     * requests both racing an empty table.
     */
    public function test_two_concurrent_submissions_for_the_last_retake_slot_admit_exactly_one(): void
    {
        $tenant = $this->provisionTenant('Race-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => 'Race Test',
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => 100,
            'max_attempts' => 2,
        ]));

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
            ->assertOk();

        // Child processes need committed fixtures. This is the last test in the
        // class and the finally block rebuilds the isolated test schema — see
        // TrainingProgressTest's identical note on why.
        DB::commit();
        $processes = [];

        try {
            $arguments = [$tenant['org']->id, $learner->id, $test->id];

            $holder = $this->startRaceWorker('submit_hold', $arguments);
            $processes[] = &$holder;
            $this->assertSame('LOCKED', $this->readRaceLine($holder), 'The holder must confirm it has the row locked before the second worker starts.');

            $second = $this->startRaceWorker('submit', $arguments);
            $processes[] = &$second;
            $this->assertSame('READY', $this->readRaceLine($second), 'The second worker must be waiting at the gate, not already racing.');

            $this->releaseRaceWorker($second);
            $this->releaseRaceWorker($holder);

            $firstResult = $this->finishRaceWorker($holder);
            $secondResult = $this->finishRaceWorker($second);
            $results = [$firstResult, $secondResult];

            $oks = array_filter($results, fn ($r) => str_starts_with($r, 'RESULT|OK|'));
            $denials = array_filter($results, fn ($r) => str_starts_with($r, 'RESULT|DENIED'));

            $this->assertCount(1, $oks, 'Exactly one of the two concurrent submissions for the last slot must be admitted: '.implode(' / ', $results));
            $this->assertCount(1, $denials, 'The other concurrent submission for the last slot must be refused, naming the cap: '.implode(' / ', $results));

            $this->inTenant($tenant['org'], function () use ($test, $learner) {
                $count = TrainingAttempt::query()
                    ->where('user_id', $learner->id)->where('test_id', $test->id)->count();

                $this->assertSame(
                    2,
                    $count,
                    'A cap of 2 must never admit a third attempt — two genuinely concurrent '
                        .'submissions for the last slot both reading room and both inserting is '
                        .'exactly the race a retake cap has to close.',
                );
            });
        } finally {
            foreach ($processes as &$process) {
                $this->closeRaceWorker($process);
            }
            app(TenantContext::class)->forget();
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    /**
     * @param  string[]  $arguments  [organization_id, user_id, test_id]
     * @return array{process: resource, pipes: array, finished: bool}
     */
    private function startRaceWorker(string $operation, array $arguments): array
    {
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$operation = $argv[1];
$organization = App\Models\Organization::query()->findOrFail($argv[2]);
$result = app(App\Support\Tenancy\TenantContext::class)->run($organization, function () use ($operation, $argv) {
    $actor = App\Models\User::query()->findOrFail($argv[3]);
    $testId = $argv[4];
    $wait = static function (string $signal): void {
        fwrite(STDOUT, $signal."\n"); fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('missing GO'); }
    };
    $submit = static function () use ($actor, $testId): string {
        $request = Illuminate\Http\Request::create('/', 'POST', ['answers' => [0, 0]]);
        $request->setUserResolver(fn () => $actor);
        try {
            $response = app(App\Http\Controllers\Api\Training\LearnController::class)->submit($request, $testId);
            $data = $response->getData(true);

            return 'OK|'.($data['passed'] ? '1' : '0');
        } catch (Illuminate\Auth\Access\AuthorizationException $e) {
            return 'DENIED|'.$e->getMessage();
        } catch (Throwable $e) {
            return 'ERROR|'.$e::class.':'.$e->getMessage();
        }
    };
    if ($operation === 'submit_hold') {
        return Illuminate\Support\Facades\DB::transaction(function () use ($testId, $wait, $submit): string {
            // Pre-lock the SAME row submit() will lock. Same connection, same
            // transaction, so submit()'s own lockForUpdate() re-acquires it
            // for free — the point is to hold it across the pause below.
            App\Models\TrainingTest::query()->where('id', $testId)->lockForUpdate()->first();
            $wait('LOCKED');

            return $submit();
        });
    }
    $wait('READY');

    return $submit();
});
fwrite(STDOUT, 'RESULT|'.$result."\n");
PHP;
        $command = [PHP_BINARY, '-r', $worker, $operation, ...$arguments];
        // The worker's database by name: under the parallel gate this process tests in
        // <DB_DATABASE>_test_<n>, which the child cannot learn from the environment.
        $env = [...getenv(), 'DB_DATABASE' => DB::connection()->getDatabaseName()];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($process);

        return ['process' => $process, 'pipes' => $pipes, 'finished' => false];
    }

    private function readRaceLine(array $worker): string
    {
        return trim((string) fgets($worker['pipes'][1]));
    }

    private function releaseRaceWorker(array $worker): void
    {
        fwrite($worker['pipes'][0], "GO\n");
        fflush($worker['pipes'][0]);
    }

    private function finishRaceWorker(array &$worker): string
    {
        $line = $this->readRaceLine($worker);
        fclose($worker['pipes'][0]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        $worker['finished'] = true;
        $this->assertSame(0, $exit, $stderr);

        return $line;
    }

    private function closeRaceWorker(array &$worker): void
    {
        if ($worker['finished'] ?? true) {
            return;
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
        $worker['finished'] = true;
    }

    // ── Automations plumbing, mirroring HrAutomationsTest's own private copy ──

    private function rule(
        array $tenant,
        string $trigger,
        ?string $scopeType,
        ?string $scopeId,
        array $actions = [['type' => 'notify', 'message' => 'Something happened in Training.']],
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

    // ── Fixtures ────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function twoQuestions(): array
    {
        // Index 1 is always the correct choice on both questions — chosen so
        // answers [1, 0] scores exactly one of two (50%) and [0, 0] scores
        // zero, which is what every fixture below relies on.
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

    /** @return array{0: User, 1: TrainingTest, 2: array} */
    private function aTwoQuestionTest(
        int $passMark,
        ?int $maxAttempts = null,
        ?int $validForDays = null,
        bool $countAttemptsAfterPass = false,
    ): array {
        $tenant = $this->provisionTenant('Grading-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => 'Two Question Test',
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => $passMark,
            'max_attempts' => $maxAttempts,
            'valid_for_days' => $validForDays,
            'count_attempts_after_pass' => $countAttemptsAfterPass,
        ]));

        return [$learner, $test, $tenant];
    }

    /** @return array{0: User, 1: TrainingGuide, 2: TrainingTest, 3: array} */
    private function aGatedTest(): array
    {
        $tenant = $this->provisionTenant('Gated-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        [$guide, $test] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $guide = TrainingGuide::create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::PUBLISHED,
                'screens' => $this->oneScreen(),
            ]);

            $test = TrainingTest::create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'guide_id' => $guide->id,
                'title' => 'Ladder Test',
                'status' => TrainingTest::PUBLISHED,
                'questions' => $this->twoQuestions(),
                'requires_guide' => true,
                'pass_mark' => 40,
            ]);

            return [$guide, $test];
        });

        return [$learner, $guide, $test, $tenant];
    }
}
