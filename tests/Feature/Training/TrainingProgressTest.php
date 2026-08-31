<?php

namespace Tests\Feature\Training;

use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\TrainingGuide;
use App\Models\TrainingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reading progress through a guide (task-7 brief) — `POST
 * /training/learn/guides/{guide}/seen`, the first write this module ever
 * makes and the first event it ever raises.
 *
 * `/training/learn/*` is the LEARNER surface, gated on `training.view` like
 * the rest of LearnController — see TrainingAnswerKeyTest's docblock for why
 * that is the opposite gate from `/training/guides` and `/training/tests`.
 *
 * ── `last_screen` is a high-water mark, not a cursor ────────────────
 *
 * Re-reading an earlier screen of a guide already finished is ordinary and
 * must not undo the completion — that is the brief's own required test, and
 * it doubles as the RED/GREEN check: change `max()` to a plain assignment in
 * ProgressController::seen and only this test should fail.
 *
 * ── The event is an outbox INSERT, never a dispatch ─────────────────
 *
 * `training.completed` is this module's first raised trigger. `Bus::fake()`
 * plus an assertion that nothing was dispatched proves the write path never
 * touches the queue directly — see EventEmitter's and StartUp.md §10's
 * reasoning. The outbox row itself is read straight off `automation_events`,
 * the transactional outbox AutomationEvent models (see ReviewEventAtomicityTest
 * for the same pattern elsewhere in the suite).
 */
class TrainingProgressTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── The brief's own test ─────────────────────────────────────────

    public function test_seeing_the_last_screen_finishes_the_guide_and_going_back_does_not_unfinish_it(): void
    {
        [$learner, $guide] = $this->aLearnerAndAnEightScreenGuide();

        $this->actingAs($learner)->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 7])
            ->assertOk()
            ->assertJsonPath('last_screen', 7)
            ->assertJsonPath('screen_count', 8)
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        // Going back to re-read screen 1 must not undo it.
        $this->actingAs($learner)->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 1])
            ->assertOk()
            ->assertJsonPath('last_screen', 7)
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);
    }

    /** The response shape named in the brief, with no assumption about key order. */
    public function test_the_response_carries_exactly_the_three_named_fields(): void
    {
        [$learner, $guide] = $this->aLearnerAndAnEightScreenGuide();

        $body = $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 2])
            ->assertOk()
            ->json();

        $this->assertEqualsCanonicalizing(['last_screen', 'completed_at', 'screen_count'], array_keys($body));
        $this->assertSame(2, $body['last_screen']);
        $this->assertSame(8, $body['screen_count']);
        $this->assertNull($body['completed_at'], 'Screen 2 of 8 is not the last screen.');
    }

    // ── The outbox, not a dispatch ───────────────────────────────────

    public function test_finishing_a_guide_inserts_an_outbox_row_and_dispatches_nothing(): void
    {
        Bus::fake();

        [$learner, $guide, $tenant] = $this->aLearnerAndAnEightScreenGuide();

        $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 7])
            ->assertOk();

        // The write path never dispatches an automation itself (StartUp.md
        // §10) — it only ever inserts a row, and
        // ezbizi:dispatch-automation-events is the separate, scheduled reader
        // that turns it into a real delivery.
        Bus::assertNothingDispatched();

        $this->inTenant($tenant['org'], function () use ($guide, $learner) {
            $event = AutomationEvent::query()->where('event_key', 'training.completed')->first();
            $progress = TrainingProgress::query()
                ->where('user_id', $learner->id)->where('guide_id', $guide->id)->firstOrFail();

            $this->assertNotNull($event, 'Finishing a guide must insert a training.completed outbox row.');
            // The subject is the progress row itself — TrainingProgress
            // carries the guide and the user, not the other way around (see
            // TrainingModuleTest on why the module names no single subject).
            $this->assertSame(TrainingProgress::class, $event->subject_type);
            $this->assertSame($progress->id, $event->subject_id);
            $this->assertSame($guide->id, $event->payload['after']['guide_id'] ?? null);
            $this->assertSame($learner->id, $event->actor_id);
            $this->assertNull($event->delivered_at, 'Inserted, not delivered — delivery is the sweep\'s job.');
        });
    }

    /**
     * Finishing twice — the second call is a genuine re-completion attempt,
     * not merely a re-read of an earlier screen: it names the SAME final
     * screen again. The event must still fire exactly once.
     */
    public function test_finishing_twice_raises_the_event_exactly_once(): void
    {
        [$learner, $guide, $tenant] = $this->aLearnerAndAnEightScreenGuide();

        $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 7])
            ->assertOk()
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 7])
            ->assertOk()
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        $this->inTenant($tenant['org'], function () use ($guide, $learner) {
            $progress = TrainingProgress::query()
                ->where('user_id', $learner->id)->where('guide_id', $guide->id)->firstOrFail();

            $count = AutomationEvent::query()
                ->where('event_key', 'training.completed')
                ->where('subject_type', TrainingProgress::class)
                ->where('subject_id', $progress->id)
                ->count();

            $this->assertSame(
                1,
                $count,
                'A duplicate event for one completion is a rule firing twice at a customer.',
            );
        });
    }

    // ── Place scope and publication — the same two filters as the read side ──

    public function test_a_learner_cannot_record_progress_against_a_siblings_guide(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $sibling = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate only', 'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->screens(8),
        ]));

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $riverside);

        $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$sibling->id}/seen", ['screen' => 0])
            ->assertNotFound();

        $this->inTenant($tenant['org'], function () use ($sibling, $learner) {
            $this->assertDatabaseMissing('training_progress', [
                'user_id' => $learner->id,
                'guide_id' => $sibling->id,
            ]);
        });
    }

    public function test_a_learner_cannot_record_progress_against_a_draft_guide(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $draft = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Unfinished Guide', 'status' => TrainingGuide::DRAFT,
            'screens' => $this->screens(8),
        ]));

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$draft->id}/seen", ['screen' => 0])
            ->assertNotFound();

        $this->inTenant($tenant['org'], function () use ($draft, $learner) {
            $this->assertDatabaseMissing('training_progress', [
                'user_id' => $learner->id,
                'guide_id' => $draft->id,
            ]);
        });
    }

    public function test_recording_progress_without_training_view_is_refused_with_a_reason(): void
    {
        [, $guide, $tenant] = $this->aLearnerAndAnEightScreenGuide();
        $noAccess = $this->userWithRole($tenant, 'NoAccess', []);

        $message = $this->actingAs($noAccess)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 0])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('permission', $message);
        $this->assertStringNotContainsString('§', $message);
        $this->assertStringNotContainsString('ProgressController', $message);
        $this->assertStringNotContainsString('immutable', $message);
    }

    // ── Validation: screen indexes into a JSON array ─────────────────

    public function test_a_negative_screen_is_refused_not_stored(): void
    {
        [$learner, $guide] = $this->aLearnerAndAnEightScreenGuide();

        $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => -1])
            ->assertStatus(422);

        $this->assertDatabaseMissing('training_progress', ['guide_id' => $guide->id]);
    }

    public function test_a_screen_past_the_end_of_the_guide_is_refused_not_stored(): void
    {
        [$learner, $guide] = $this->aLearnerAndAnEightScreenGuide();

        // 8 screens are indices 0..7 — 8 itself is one past the end.
        $this->actingAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 8])
            ->assertStatus(422);

        $this->assertDatabaseMissing('training_progress', ['guide_id' => $guide->id]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /** @return array{0: User, 1: TrainingGuide, 2: array{org: Organization}} */
    private function aLearnerAndAnEightScreenGuide(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => 'Eight Screen Guide',
            'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->screens(8),
        ]));

        return [$learner, $guide, $tenant];
    }

    /** @return array<int, array<string, mixed>> */
    private function screens(int $count): array
    {
        return array_map(
            fn (int $n) => ['title' => "Screen {$n}", 'body' => 'Content.', 'media_kind' => 'none', 'media_url' => null],
            range(1, $count),
        );
    }
}
