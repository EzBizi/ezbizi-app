<?php

namespace Tests\Feature\Training;

use App\Models\Assignment;
use App\Models\AutomationEvent;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TrainingGuide;
use App\Models\TrainingProgress;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

        $this->completeThrough($learner, $guide, 7)
            ->assertOk()
            ->assertJsonPath('last_screen', 7)
            ->assertJsonPath('screen_count', 8)
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        // Going back to re-read screen 1 must not undo it.
        $this->completeThrough($learner, $guide, 1)
            ->assertOk()
            ->assertJsonPath('last_screen', 7)
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);
    }

    /** The response shape named in the brief, with no assumption about key order. */
    public function test_the_response_carries_exactly_the_three_named_fields(): void
    {
        [$learner, $guide] = $this->aLearnerAndAnEightScreenGuide();

        $body = $this->completeThrough($learner, $guide, 2)
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

        $this->completeThrough($learner, $guide, 7)
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

        $this->completeThrough($learner, $guide, 7)
            ->assertOk()
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        $this->completeThrough($learner, $guide, 7)
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

    // ── The event's automation scope is the LEARNER'S place, not the guide's ──

    /**
     * `training_progress` carries no `franchise_id`/`company_id` of its own
     * (see the migration), so without `CarriesAutomationScope` every
     * `training.completed` event would arrive at both `AutomationEngine`
     * and `NotificationDispatcher` claiming no scope — which the latter
     * reads as "every active user in the organization" (see
     * `NotificationDispatcher::audienceFor`'s own docblock on exactly this
     * shape of leak, first found in Human Resources).
     *
     * The motivating case for "the learner's place, not the guide's":
     * delegating to the guide fails it. This guide is filed at the
     * ORGANIZATION (`company_id`/`franchise_id` both null) — reachable by
     * every franchise, which is the whole point of publishing one there —
     * so a guide-derived scope would resolve to `[null, null]` regardless
     * of which franchise the learner who finished it actually works at, and
     * "tell their supervisor" would never fire for exactly the learners an
     * organization-wide guide reaches most unevenly. Resolving from the
     * learner's own assignment instead answers "who should be told" rather
     * than "who may see this guide" — a different question with a
     * different answer.
     */
    public function test_automation_scope_resolves_to_the_learners_own_assignment_on_an_organization_wide_guide(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $orgWideGuide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Org Wide Guide', 'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->screens(1),
        ]));

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $riverside);

        $this->completeThrough($learner, $orgWideGuide, 0)
            ->assertOk()
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        $this->inTenant($tenant['org'], function () use ($orgWideGuide, $learner, $riverside) {
            $progress = TrainingProgress::query()
                ->where('user_id', $learner->id)->where('guide_id', $orgWideGuide->id)->firstOrFail();

            $this->assertSame(
                ['franchise', $riverside->id],
                $progress->automationScope(),
                'Delegating to the guide\'s own (organization-wide, i.e. null/null) place would '
                    .'have silently stranded every franchise- or company-scoped rule on this event — '
                    .'the guide answers "who may see this", a completion answers "who should be told".',
            );
        });
    }

    /**
     * Coherence audit finding 6: §4 lets a person hold SEVERAL assignments,
     * and `assignments()->first()` carried no ordering — MySQL was free to
     * hand back either row, so which supervisor "who should be told"
     * resolved to could differ between two runs of the identical
     * completion. Given two assignments, the OLDEST one now always wins,
     * proven here by asserting the answer against the older row's place
     * (Riverside) though the newer one (Northgate) was created after it —
     * a test built the other way around (asserting against whichever the
     * old code happened to return) would not have caught the
     * non-determinism at all.
     */
    public function test_automation_scope_is_deterministic_when_the_learner_holds_two_assignments(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $orgWideGuide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Org Wide Guide', 'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->screens(1),
        ]));

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $riverside);

        $this->inTenant($tenant['org'], function () use ($tenant, $learner) {
            $northgate = Franchise::create(['company_id' => $tenant['company']->id, 'name' => 'Northgate']);

            // The Riverside row already exists (`userWithRole`'s `onlyAt`) and
            // is backdated so its `created_at` is unambiguously the OLDER of
            // the two even at second precision.
            $learner->assignments()->first()->forceFill(['created_at' => now()->subMinute()])->save();

            Assignment::create([
                'organization_id' => $tenant['org']->id, 'user_id' => $learner->id,
                'assigned_franchise_id' => $northgate->id,
            ]);
        });

        $this->completeThrough($learner, $orgWideGuide, 0)
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($orgWideGuide, $learner, $riverside) {
            $progress = TrainingProgress::query()
                ->where('user_id', $learner->id)->where('guide_id', $orgWideGuide->id)->firstOrFail();

            $this->assertSame(
                ['franchise', $riverside->id],
                $progress->automationScope(),
                'The OLDER assignment (Riverside) must win, deterministically, over the newer one (Northgate).',
            );
        });
    }

    /**
     * The behavioral test above proves the CHOSEN row is deterministic, but
     * MySQL returning an unordered result in creation order often enough
     * that the assertion happens to pass is exactly the trap an unordered
     * `first()` sets — this asserts the ORDER BY clause itself is on the
     * query, which cannot pass by coincidence the way a value assertion
     * can.
     */
    public function test_automation_scope_query_carries_an_explicit_order_by(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $tenant['franchise']);

        $progress = $this->inTenant($tenant['org'], fn () => TrainingProgress::create([
            'user_id' => $learner->id,
            'guide_id' => TrainingGuide::create([
                'company_id' => null, 'franchise_id' => null,
                'title' => 'Org Wide Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ])->id,
            'last_screen' => 1,
        ]));

        $this->inTenant($tenant['org'], function () use ($progress) {
            DB::enableQueryLog();
            $progress->automationScope();
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
     * The other two shapes `Onboarding::automationScope` — the model this
     * one's own docblock says it copies — resolves: company-assigned and
     * genuinely unassigned (falls back to no scope, same conservative
     * direction `Onboarding::scope()` takes when it has nothing narrower).
     *
     * Built directly against the model rather than through the real
     * endpoint: a user with literally no `Assignment` row has no §4 reach
     * at all, so `InventoryVisibility` would 404 them out of even an
     * organization-wide guide before `automationScope()` ever entered the
     * picture — orthogonal to what this test is about.
     */
    public function test_automation_scope_resolves_company_and_falls_back_to_none_when_unassigned(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Org Wide Guide', 'status' => TrainingGuide::PUBLISHED,
            'screens' => $this->screens(1),
        ]));

        $companyLearner = $this->userAtCompany($tenant, 'CompanyLearner');
        $unassignedLearner = $this->userAssignedNowhere($tenant, 'Unassigned');

        [$companyScope, $unassignedScope] = $this->inTenant(
            $tenant['org'],
            function () use ($guide, $companyLearner, $unassignedLearner) {
                $companyProgress = TrainingProgress::create([
                    'user_id' => $companyLearner->id, 'guide_id' => $guide->id,
                    'last_screen' => 0, 'completed_at' => now(),
                ]);
                $unassignedProgress = TrainingProgress::create([
                    'user_id' => $unassignedLearner->id, 'guide_id' => $guide->id,
                    'last_screen' => 0, 'completed_at' => now(),
                ]);

                return [$companyProgress->automationScope(), $unassignedProgress->automationScope()];
            },
        );

        $this->assertSame(['company', $tenant['company']->id], $companyScope);
        $this->assertSame([null, null], $unassignedScope);
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

        $this->completeThrough($learner, $sibling, 0)
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

        $this->completeThrough($learner, $draft, 0)
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

        $message = $this->completeThrough($noAccess, $guide, 0)
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

        $this->signedInAs($learner)
            ->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => -1])
            ->assertStatus(422);

        $this->assertDatabaseMissing('training_progress', ['guide_id' => $guide->id]);
    }

    public function test_a_screen_past_the_end_of_the_guide_is_refused_not_stored(): void
    {
        [$learner, $guide] = $this->aLearnerAndAnEightScreenGuide();

        // 8 screens are indices 0..7 — 8 itself is one past the end.
        $this->signedInAs($learner)->postJson("/api/v1/training/learn/guides/{$guide->id}/seen", ['screen' => 8])
            ->assertStatus(422);

        $this->assertDatabaseMissing('training_progress', ['guide_id' => $guide->id]);
    }

    // ── The real race, not the sequential one ────────────────────────

    /**
     * `test_finishing_twice_raises_the_event_exactly_once` proves the quiet
     * re-read: two SEQUENTIAL calls in one PHP process, one connection, one
     * transaction after another. It cannot fail on the race a review found —
     * two requests genuinely overlapping, both reading `completed_at: null`
     * before either has written — because nothing in that test ever holds a
     * transaction open while a second one starts.
     *
     * This test does that for real: two separate PHP processes, two separate
     * database connections, synchronized so the second one's `lockForUpdate()`
     * is provably still blocked on the first when it starts. It is the same
     * shape `TaskConcurrencyTest::test_private_membership_is_rechecked_under_the_same_locks_for_both_race_outcomes`
     * uses for the identical reason, adapted because `seen()` has no injectable
     * pause point of its own: `writer_hold` pre-acquires the SAME row lock
     * `seen()` will re-acquire (harmlessly — same connection, same
     * transaction) and holds it across the pause, so the moment it is
     * released the second worker — already blocked inside its own
     * `lockForUpdate()` — proceeds and finds `completed_at` already set.
     *
     * `DB::commit()` is required first: `RefreshDatabase` wraps this test in
     * an outer transaction the child processes' own connections cannot see
     * into, so the fixture (org, learner, guide, and the progress row parked
     * at screen 6 of 8) has to be made durable for real before they start.
     * That is also why this is the LAST test in the class and the `finally`
     * block ends with `migrate:fresh` — the manual commit is not rolled back
     * by `tearDown()`, so the schema is rebuilt clean for whatever runs next.
     */
    public function test_two_concurrent_finishes_of_the_same_guide_raise_the_event_exactly_once(): void
    {
        [$learner, $guide, $tenant] = $this->aLearnerAndAnEightScreenGuide();

        // Screens 0-6 of an 8-screen guide (indices 0..7): not yet finished.
        // The reviewer's own description of the dangerous case — "the
        // learner created the row reading screens 0–6, so a double-tap or a
        // client retry lands exactly there" — is the state built here.
        $this->completeThrough($learner, $guide, 6)
            ->assertOk()
            ->assertJsonPath('completed_at', null);

        DB::commit();
        $processes = [];

        try {
            $arguments = [$tenant['org']->id, $learner->id, $guide->id];

            $holder = $this->startRaceWorker('writer_hold', $arguments);
            $processes[] = &$holder;
            $this->assertSame('LOCKED', $this->readRaceLine($holder), 'The holder must confirm it has the row locked before the second worker starts.');

            $second = $this->startRaceWorker('writer', $arguments);
            $processes[] = &$second;
            $this->assertSame('READY', $this->readRaceLine($second), 'The second worker must be waiting at the gate, not already racing.');

            // Order matters for nothing here except realism: releasing the
            // second worker first sends it straight into a lockForUpdate()
            // that blocks on the database engine — invisibly, from this
            // process's point of view — until the holder below commits.
            $this->releaseRaceWorker($second);
            $this->releaseRaceWorker($holder);

            $firstResult = $this->finishRaceWorker($holder);
            $secondResult = $this->finishRaceWorker($second);

            $this->assertStringStartsWith('RESULT|OK|', $firstResult, $firstResult);
            $this->assertStringStartsWith('RESULT|OK|', $secondResult, $secondResult);

            $this->inTenant($tenant['org'], function () use ($guide, $learner) {
                $progress = TrainingProgress::query()
                    ->where('user_id', $learner->id)->where('guide_id', $guide->id)->firstOrFail();

                $this->assertSame(7, $progress->last_screen);
                $this->assertNotNull($progress->completed_at);

                $count = AutomationEvent::query()
                    ->where('event_key', 'training.completed')
                    ->where('subject_type', TrainingProgress::class)
                    ->where('subject_id', $progress->id)
                    ->count();

                $this->assertSame(
                    1,
                    $count,
                    'Two genuinely concurrent requests finishing the same guide raised the '
                        .'event more than once — exactly the race a duplicate rule-firing bug '
                        .'looks like at a customer.',
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
     * @param  string[]  $arguments  [organization_id, user_id, guide_id]
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
    $guideId = $argv[4];
    $wait = static function (string $signal): void {
        fwrite(STDOUT, $signal."\n"); fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('missing GO'); }
    };
    $write = static function () use ($actor, $guideId): string {
        $request = Illuminate\Http\Request::create('/', 'POST', ['screen' => 7]);
        $request->setUserResolver(fn () => $actor);
        try {
            $response = app(App\Http\Controllers\Api\Training\ProgressController::class)->seen($request, $guideId);
            $data = $response->getData(true);

            return 'OK|'.($data['completed_at'] !== null ? '1' : '0');
        } catch (Throwable $e) {
            return 'ERROR|'.$e::class.':'.$e->getMessage();
        }
    };
    if ($operation === 'writer_hold') {
        return Illuminate\Support\Facades\DB::transaction(function () use ($actor, $guideId, $wait, $write): string {
            // Pre-lock the SAME row seen() will lock. Same connection, same
            // transaction, so seen()'s own lockForUpdate() re-acquires it
            // for free — the point is to hold it across the pause below.
            App\Models\TrainingProgress::query()
                ->where('user_id', $actor->id)->where('guide_id', $guideId)
                ->lockForUpdate()->first();
            $wait('LOCKED');

            return $write();
        });
    }
    $wait('READY');

    return $write();
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

    /**
     * A user assigned at the COMPANY level rather than a franchise —
     * `BuildsTenants::userWithRole`'s `onlyAt` only accepts a Franchise, so
     * this is `TrainingGuideScopeTest::userAtCompany`'s own private copy,
     * for the same reason (see that class's docblock on it).
     */
    private function userAtCompany(array $tenant, string $roleName): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $roleName) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => $roleName,
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['training.view']);

            $user = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => $roleName,
                'email' => strtolower($roleName).'@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $tenant['company']->id,
            ]);

            return $user;
        });
    }

    /**
     * A user with NO `Assignment` row at all — `userWithRole` always
     * creates one (organization-level by default), so this is the one shape
     * it cannot build: `Onboarding::scope()`'s "stands nowhere" fallback.
     */
    private function userAssignedNowhere(array $tenant, string $roleName): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $roleName) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => $roleName,
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['training.view']);

            return User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => $roleName,
                'email' => strtolower($roleName).'@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);
        });
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
