<?php

namespace Tests\Feature\Training;

use App\Models\AutomationEvent;
use App\Models\TrainingAttempt;
use App\Models\TrainingTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The nightly sweep that notices a training pass has run out (task-10
 * brief) — `ezbizi:expire-training-passes`.
 *
 * Consumes what `LearnController::submit` writes: a PASSED attempt whose
 * test carries `valid_for_days` gets an `expires_on`. This sweep is the only
 * thing that ever looks at that date again, and `training.expired` is what
 * lets an organization write "when the ladder-safety pass runs out, assign
 * the guide again."
 *
 * ── The outbox, not a dispatch ────────────────────────────────────
 *
 * Same boundary as `TrainingGradingTest`'s own outbox tests: `Bus::fake()`
 * alone proves nothing, because nothing on this path ever dispatches — the
 * real assertion is that the row landed in `automation_events` with
 * `delivered_at` still null.
 *
 * ── Idempotence and supersession ───────────────────────────────────
 *
 * Running the sweep twice must announce one lapse once — proven by running
 * the artisan command twice and counting `automation_events` rows, not by
 * calling a private method twice. And a pass superseded by a later pass
 * (retaken and passed again after the lapsing one) must never fire; see
 * `test_a_superseded_pass_never_fires` and its sibling below for both the
 * still-current and the also-lapsed shapes of "superseded".
 */
class TrainingExpiryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_lapsed_pass_queues_an_event_rather_than_dispatching_one(): void
    {
        [, , $tenant, $attempt] = $this->aPassedAttemptExpiring(daysAgo: 1);

        Bus::fake();                       // nothing may be dispatched inline
        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        Bus::assertNothingDispatched();

        $this->inTenant($tenant['org'], function () use ($attempt) {
            $event = AutomationEvent::query()->where('event_key', 'training.expired')->first();

            $this->assertNotNull($event, 'A lapsed passed attempt must insert a training.expired outbox row.');
            $this->assertSame(TrainingAttempt::class, $event->subject_type);
            $this->assertSame($attempt->id, $event->subject_id);
            $this->assertNull($event->delivered_at, 'Inserted, not delivered — delivery is the dispatcher sweep\'s job.');
        });
    }

    public function test_running_the_sweep_twice_announces_a_lapsed_pass_once(): void
    {
        [, , $tenant] = $this->aPassedAttemptExpiring(daysAgo: 1);

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();
        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, DB::table('automation_events')
                ->where('event_key', 'training.expired')->count());
        });
    }

    public function test_a_pass_not_yet_lapsed_raises_nothing(): void
    {
        [, , $tenant] = $this->aPassedAttemptExpiring(daysAgo: -5);

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, DB::table('automation_events')
                ->where('event_key', 'training.expired')->count());
        });
    }

    /**
     * A pass expiring exactly today is lapsed, not merely "about to" —
     * "the end of the period it was good for" (the trigger's own
     * description) includes the last day itself.
     */
    public function test_a_pass_expiring_today_raises_the_event(): void
    {
        [, , $tenant] = $this->aPassedAttemptExpiring(daysAgo: 0);

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, DB::table('automation_events')
                ->where('event_key', 'training.expired')->count());
        });
    }

    /**
     * `expires_on` on a failed attempt should never exist in practice
     * (LearnController::submit only stamps it on a pass), but the column
     * permits it, and the sweep must not treat it as a lapsed pass.
     */
    public function test_a_failed_attempt_with_an_expires_on_raises_nothing(): void
    {
        $tenant = $this->provisionTenant('Failed-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant);

        $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [0, 0],
            'score' => 0,
            'passed' => false,
            'taken_at' => now()->subDays(31),
            'expires_on' => now()->subDay()->toDateString(),
        ]));

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, DB::table('automation_events')
                ->where('event_key', 'training.expired')->count());
        });
    }

    /**
     * Superseded by a still-current retake: the earlier lapse is not news
     * because the person's CURRENT standing is the later pass, which has
     * not itself lapsed. Zero events — not one for the earlier attempt.
     */
    public function test_a_pass_superseded_by_a_still_current_retake_never_fires(): void
    {
        [$learner, $test, $tenant, $earlier] = $this->aPassedAttemptExpiring(daysAgo: 1);

        $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => $earlier->taken_at->copy()->addHour(),
            'expires_on' => now()->addDays(20)->toDateString(),
        ]));

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, DB::table('automation_events')
                ->where('event_key', 'training.expired')->count());
        });
    }

    /**
     * Superseded, and the retake has ALSO since lapsed: the CURRENT pass —
     * the later attempt — is the one that is news, not the one it
     * superseded. Exactly one event, and it names the later attempt.
     */
    public function test_a_pass_superseded_by_an_also_lapsed_retake_announces_only_the_later_one(): void
    {
        [$learner, $test, $tenant, $earlier] = $this->aPassedAttemptExpiring(daysAgo: 40);

        $later = $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => $earlier->taken_at->copy()->addDays(10),
            'expires_on' => now()->subDay()->toDateString(),
        ]));

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($earlier, $later) {
            $events = AutomationEvent::query()->where('event_key', 'training.expired')->get();

            $this->assertCount(1, $events, 'Exactly one lapsed pass is news: the current one.');
            $this->assertSame($later->id, $events->first()->subject_id);
        });
    }

    /**
     * `isSuperseded()` compares `taken_at`, not `expires_on`, and the two CAN
     * disagree about which attempt is "later". Here the attempt taken MORE
     * RECENTLY has the OLDER `expires_on` of the two — and the sweep still
     * follows `taken_at`: it announces the attempt taken most recently, even
     * though the OTHER attempt's `expires_on` is the more recent lapse in
     * absolute date terms, and that other attempt never fires at all.
     */
    public function test_when_taken_at_and_expires_on_disagree_taken_at_decides(): void
    {
        $tenant = $this->provisionTenant('Disagree-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant);

        // Taken 31 days ago; lapsed only 1 day ago — the MORE RECENT lapse by
        // expires_on, but the EARLIER attempt by taken_at.
        $earlierByTakenAt = $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDays(31),
            'expires_on' => now()->subDay()->toDateString(),
        ]));

        // Taken 20 days ago; lapsed 10 days ago — the LATER attempt by
        // taken_at, but an OLDER lapse by expires_on.
        $laterByTakenAt = $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDays(20),
            'expires_on' => now()->subDays(10)->toDateString(),
        ]));

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($earlierByTakenAt, $laterByTakenAt) {
            $events = AutomationEvent::query()->where('event_key', 'training.expired')->get();

            $this->assertCount(1, $events);
            $this->assertSame($laterByTakenAt->id, $events->first()->subject_id,
                'isSuperseded() compares taken_at, so the attempt taken more recently is the '
                .'one announced, even though the other attempt lapsed more recently by date.');
        });
    }

    /**
     * The sweep crosses tenants, deliberately — it runs `runWithoutTenant()`
     * with no organization in context, and every other fixture in this file
     * provisions exactly one tenant, so nothing before this test could catch
     * a regression to a silently single-tenant sweep.
     */
    public function test_the_sweep_reaches_more_than_one_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha-'.Str::random(8));
        $beta = $this->provisionTenant('Beta-'.Str::random(8));

        $attempts = [];

        foreach ([$alpha, $beta] as $tenant) {
            $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
            $test = $this->aTest($tenant);

            $attempts[$tenant['org']->id] = $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
                'user_id' => $learner->id,
                'test_id' => $test->id,
                'answers' => [1, 0],
                'score' => 90,
                'passed' => true,
                'taken_at' => now()->subDays(31),
                'expires_on' => now()->subDay()->toDateString(),
            ]));
        }

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        foreach ([$alpha, $beta] as $tenant) {
            $this->inTenant($tenant['org'], function () use ($tenant, $attempts) {
                $event = AutomationEvent::query()->where('event_key', 'training.expired')->first();

                $this->assertNotNull($event, "No training.expired raised for {$tenant['org']->name}.");
                $this->assertSame($attempts[$tenant['org']->id]->id, $event->subject_id);
            });
        }
    }

    /**
     * The Critical from review round 1, proven closed the same way it was
     * proven open: announce a lapse, delete the resulting outbox row (standing
     * in for `ezbizi:expire-data` pruning it three months later), re-run the
     * sweep, and assert there is NO second event. Before
     * `training_attempts.expiry_announced_at` existed, this exact sequence
     * inserted a second `training.expired` row for the same attempt — the
     * outbox row was the only marker, and once it was gone the sweep had no
     * memory that the lapse had ever been announced.
     */
    public function test_deleting_the_announced_outbox_row_does_not_reannounce_the_lapse(): void
    {
        [, , $tenant, $attempt] = $this->aPassedAttemptExpiring(daysAgo: 1);

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($attempt) {
            $this->assertSame(1, DB::table('automation_events')
                ->where('event_key', 'training.expired')
                ->where('subject_id', $attempt->id)->count());

            // Simulate ezbizi:expire-data pruning the delivered outbox row
            // three months later.
            DB::table('automation_events')
                ->where('event_key', 'training.expired')
                ->where('subject_id', $attempt->id)
                ->delete();
        });

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($attempt) {
            $this->assertSame(0, DB::table('automation_events')
                ->where('event_key', 'training.expired')
                ->where('subject_id', $attempt->id)->count(),
                'The outbox row is gone and it was never a marker of its own — '
                .'training_attempts.expiry_announced_at is what stops the reannounce, and it '
                .'is still set.');

            $this->assertNotNull(
                TrainingAttempt::find($attempt->id)->expiry_announced_at,
                'expiry_announced_at should still be set even though the outbox row it '
                .'produced was deleted.',
            );
        });
    }

    /**
     * The payload carries no more than the published description promises
     * — never `score` or `answers` (§9 ACTIVITY personal data a `webhook`
     * action would ship verbatim to a tenant-chosen URL).
     */
    public function test_the_payload_carries_no_score_or_answers(): void
    {
        [, , $tenant, $attempt] = $this->aPassedAttemptExpiring(daysAgo: 1);

        $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($attempt) {
            $event = AutomationEvent::query()->where('event_key', 'training.expired')->firstOrFail();

            $this->assertArrayNotHasKey('score', $event->payload['after']);
            $this->assertArrayNotHasKey('answers', $event->payload['after']);
            $this->assertSame($attempt->test_id, $event->payload['after']['test_id']);
            $this->assertSame($attempt->user_id, $event->payload['after']['user_id']);
            $this->assertSame($attempt->expires_on->toDateString(), $event->payload['after']['expires_on']);
        });
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

    private function aTest(array $tenant): TrainingTest
    {
        return $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => 'Ladder Safety Test',
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'requires_guide' => false,
            'pass_mark' => 40,
            'valid_for_days' => 30,
        ]));
    }

    /**
     * A passed attempt whose `expires_on` is `$daysAgo` days in the past
     * (negative means still in the future — not yet lapsed).
     *
     * @return array{0: User, 1: TrainingTest, 2: array, 3: TrainingAttempt}
     */
    private function aPassedAttemptExpiring(int $daysAgo): array
    {
        $tenant = $this->provisionTenant('Expiry-'.Str::random(8));
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);
        $test = $this->aTest($tenant);

        $attempt = $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDays(30 + $daysAgo),
            'expires_on' => now()->subDays($daysAgo)->toDateString(),
        ]));

        return [$learner, $test, $tenant, $attempt];
    }
}
