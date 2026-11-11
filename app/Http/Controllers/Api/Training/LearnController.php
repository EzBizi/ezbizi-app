<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingProgress;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Automations\EventEmitter;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Training\LapsedPasses;
use App\Support\Training\TrainingMediaLinks;
use App\Support\Training\LearnerView;
use App\Support\Training\TestGrader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The learner-facing read — what somebody is assigned, what else is on
 * offer, and one endpoint with a genuine trust boundary: a test handed back
 * with its answer key removed.
 *
 * Gated on `training.view`, the LEARNER tier — never `training.manage`,
 * which is the authoring surface's gate (see TrainingGuidePolicy's and
 * TrainingTestPolicy's docblocks: "one permission, one controller" there
 * means this one is the other controller). No Policy class backs this one:
 * there is a single ability ("can this person learn at all") and a
 * place-and-publication filter applied uniformly to every read, which is
 * simple enough to state directly here rather than through a `view` ability
 * only this controller would ever call.
 *
 * ── The trust boundary ────────────────────────────────────────────
 *
 * `questions` carries `correct` inside the same JSON as the question text
 * (see TrainingTest's docblock). Every array this controller hands back that
 * touches a test's questions goes through LearnerView::stripAnswerKey —
 * never `$test->questions` raw, never `$test->toArray()`, never a model
 * serialized directly into the response. That includes the LIST endpoint,
 * not only [showTest]: a test mentioned inside `library` or `assigned`
 * carries the same stripped shape as one fetched on its own, because a
 * second unstripped path is exactly the kind of drift this boundary exists
 * to prevent.
 *
 * ── The place-scope boundary, and why `assigned` reads from `library`'s
 *    own data instead of its own ─────────────────────────────────────
 *
 * An earlier version of this controller computed `assigned` straight off
 * `TrainingAssignment`'s `guide`/`test` relations, checking only each
 * target's `status` — no InventoryVisibility check at all. That let a
 * learner see a sibling franchise's test the moment anything assigned it to
 * them, because the assignee-union query has no reason on its own to know
 * about place. It also decided "is this row's target published" and "which
 * target do I render" independently, so a row carrying both a published
 * `guide_id` and a draft `test_id` (the schema does not forbid a row
 * carrying both — see [presentAssignment]) could pass the publication check
 * on the guide and render the draft test anyway.
 *
 * [readableGuides] and [readableTests] are the one place both leaks close at
 * once: they are the SAME InventoryVisibility-constrained, published-only
 * queries [library] reads from, and [presentAssignment] renders an
 * assignment's target by looking it up in those collections rather than by
 * touching `$assignment->guide` or `$assignment->test` at all. A target
 * that would not appear in `library` cannot appear in `assigned` either,
 * because they are drawn from the identical set — and a row's inclusion and
 * its rendered content are now the same lookup, not two.
 *
 * ── A lapsed pass reappears here (training task-11 brief) ──────────
 *
 * §14 of the spec promises "a pass past `valid_for_days` reappears in Learn
 * as due for retaking" — and until task 11, nothing kept that promise:
 * `assigned` reads `training_assignments` only, and a lapsed pass writes no
 * new assignment row of its own unless a tenant happens to have authored a
 * `training.expired` → re-assign rule. Most tenants have not, and
 * `training.expired`'s own published description already says a pass
 * reappearing here is what the trigger MEANS — so a learner with no such
 * rule simply never saw their own lapsed test again.
 *
 * [lapsedTestIds] answers "which of the tests THIS learner can even see are
 * ones their own current pass has lapsed on" — `LapsedPasses::currentlyLapsedQuery`,
 * the IDENTICAL definition `ExpireTrainingPasses`'s nightly sweep and
 * `DashboardController`'s `retaking` count use, narrowed to `$tests` (the
 * same InventoryVisibility-constrained, published-only set [library] and
 * [assigned] already draw from) and to this one user. Every test-shaped
 * entry this controller ever renders — in `library`, in a real `assigned`
 * row, or in the synthetic one below — carries `due_for_retaking` off this
 * SAME set, via [presentTest]'s own parameter, so the three can never
 * disagree about which tests are lapsed for this person.
 *
 * `assigned` is where it reappears, not merely `library`: a lapsed pass is
 * something this person has to act on, the same shelf a real assignment
 * already occupies for exactly that reason. [assigned] adds one synthetic
 * row — no `assignment_id`, no `due_on`, because there is no real
 * assignment row backing it — for every lapsed test NOT already covered by
 * a genuine `training_assignments` row for that same test (an automation
 * rule, or a monitor re-assigning it by hand, either one already puts it on
 * this shelf). Checked against the SAME resolution [presentAssignment]
 * itself uses — test tried first when a row names both — so "was this test
 * already shown by a real assignment" can never drift from what
 * [presentAssignment] actually rendered. That is the double-count [assigned]
 * exists to prevent: one row, not two, for the same test.
 */
class LearnController extends Controller
{
    public function __construct(private readonly EventEmitter $events) {}

    /** GET /api/v1/training/learn */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLearner($user);

        $scope = ViewScope::forUser($user);
        $guides = $this->readableGuides($user, $scope);
        $tests = $this->readableTests($user, $scope);
        $lapsedTestIds = $this->lapsedTestIds($user, $tests);
        $guideProgress = $this->guideProgress($user, $guides);
        $lastAttempts = $this->lastAttempts($user, $tests);
        $attemptCounts = $this->attemptCounts($user, $tests);
        $attemptsTowardCap = $this->attemptsTowardCap($user, $tests);

        return response()->json([
            'assigned' => $this->assigned($user, $guides, $tests, $lapsedTestIds, $guideProgress, $lastAttempts, $attemptCounts, $attemptsTowardCap),
            'library' => $this->library($guides, $tests, $lapsedTestIds, $guideProgress, $lastAttempts, $attemptCounts, $attemptsTowardCap),
        ]);
    }

    /**
     * GET /api/v1/training/learn/videos - every video in the guides this person
     * may read, with its screen's title and text, so the assistant can answer
     * "how do I do X" with the right clip (Jose, 2026-11-09).
     */
    public function videos(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLearner($user);
        $search = mb_strtolower(trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '')));

        $out = [];
        foreach ($this->readableGuides($user, ViewScope::forUser($user)) as $guide) {
            foreach (\App\Support\Training\GuideScreens::topics($guide) as $t) {
                $text = \App\Support\Training\GuideScreens::plainText($t['topic']);
                foreach ($t['topic']['blocks'] ?? [] as $block) {
                    $url = (string) ($block['url'] ?? '');
                    if (($block['type'] ?? null) !== 'video' || $url === '') {
                        continue;
                    }
                    $row = ['guide' => $guide->title, 'chapter' => $t['chapter'], 'title' => $t['title'],
                        'text' => mb_substr(trim(str_replace($url, '', $text)), 0, 300), 'video' => $url];
                    // ponytail: every word must appear; a ranked search when 134 clips outgrow it.
                    $hay = mb_strtolower(implode(' ', [$guide->title, $t['chapter'], $t['title'], $row['text']]));
                    if ($search === '' || collect(preg_split('/\s+/', $search))->every(fn ($w) => str_contains($hay, $w))) {
                        $out[] = $row;
                    }
                }
            }
        }

        return response()->json(['data' => $out, 'total' => count($out)]);
    }

    /** GET /api/v1/training/learn/tests/{test} */
    public function showTest(Request $request, string $test): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLearner($user);

        $scope = ViewScope::forUser($user);

        // No implicit route-model binding: binding by id alone would resolve
        // (and 404 on) existence only, before the scope and publication
        // filters get a say. Querying through both at once means a test that
        // exists but is a sibling franchise's, or still a draft, comes back
        // 404 exactly like one that does not exist at all — the same
        // "not found" a learner gets for any of the three, and no signal
        // that distinguishes them.
        $row = InventoryVisibility::constrain(
            TrainingTest::query()->where('status', TrainingTest::PUBLISHED),
            $user,
            $scope,
        )->findOrFail($test);

        $lapsedTestIds = LapsedPasses::currentlyLapsedQuery($row->id)
            ->where('training_attempts.user_id', $user->getKey())
            ->exists() ? [$row->id] : [];

        // Same fields [presentTest] carries in `library`/`assigned`, kept
        // consistent here rather than a thinner shape for this one call
        // site — a second, unstripped-looking entry shape is exactly the
        // kind of drift this class's own docblock warns about for the
        // answer-key boundary, and the reasoning is identical for this one:
        // one presenter, one shape, every time it is used. One extra query
        // scoped to a single (user, test) pair — no N+1 risk on an endpoint
        // that already does one row lookup and one `exists()` check.
        $lastAttempt = TrainingAttempt::query()
            ->where('user_id', $user->getKey())
            ->where('test_id', $row->id)
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->first();

        // The exact count [_capReached] (test_player.dart) USED to gate on
        // — see [attemptCounts]'s own docblock — and the learner-facing
        // read never used to send at all. One more query scoped to this
        // single (user, test) pair, same reasoning as $lastAttempt just
        // above: no N+1 risk on an endpoint that already does two.
        $attemptsTaken = TrainingAttempt::query()
            ->where('user_id', $user->getKey())
            ->where('test_id', $row->id)
            ->count();

        // [attemptsTowardCap]'s own docblock: the SEPARATE number
        // [_capReached] actually gates on since the fifth-option brief —
        // never $attemptsTaken above, which stays the plain historical
        // total (`TrainingLearnProgressFieldsTest`'s own contract) and can
        // now read HIGHER than what the cap is actually counting.
        $attemptsTowardCap = $this->attemptsTowardCapForOne($user, $row);

        return response()->json([
            'data' => $this->presentTest($row, $lapsedTestIds, $lastAttempt, $attemptsTaken, $attemptsTowardCap),
        ]);
    }

    /**
     * POST /api/v1/training/learn/tests/{test}/attempts (task 8)
     *
     * Marks against the STORED test, in order: the gate, the retake cap, the
     * grade, the expiry. Never against anything the client sent — see
     * TestGrader's own docblock.
     *
     * ── The gate (decision 1) ──────────────────────────────────────
     *
     * `requires_guide: true` with a null `guide_id` is read as NO gate: the
     * test is takeable. Authoring refuses that combination
     * (TestsController::assertGuideGateHasATarget), but `guide_id` is
     * `nullOnDelete`, so deleting the guide reaches this with no API call of
     * its own. The house rule is no dead ends — a test nobody can ever sit
     * is one — so a null `guide_id` is never treated as an unsatisfiable
     * gate.
     *
     * ── The retake cap is a race, not a read-then-write check ────────
     *
     * `max_attempts` is enforced inside the SAME transaction as the write,
     * against a LOCKED read of the test — never a plain count taken before
     * the transaction opens. Attempts are inserts, not upserts, so there is
     * no existing attempt row a first submission could lock the way task 7
     * locked `training_progress` after `insertOrIgnore`
     * (ProgressController::seen). What is locked instead is the TEST row
     * itself: the one row every submission against it already reads, so
     * re-selecting it here with `lockForUpdate()` serializes every
     * submission against this test through that one row: lock the parent
     * row every writer already reads, rather than a membership row that
     * might not exist yet.
     * Read-then-write with nothing in between is not a uniqueness rule, it
     * is a race (StaffAccountsController::update's docblock).
     *
     * ── The fifth option changes what is counted, never the lock ─────
     *
     * `count_attempts_after_pass` (fifth-option brief, 2026-09-09) only
     * decides which attempts the count below includes — `LapsedPasses`'s
     * definition of "taken while an earlier pass was still valid" versus a
     * plain `COUNT(*)`. It never changes that the count is taken against the
     * SAME locked test row, inside the SAME transaction, before the SAME
     * insert: the race this section documents is closed by the lock, not by
     * which attempts get counted, and stays closed identically either way.
     *
     * ── The event, and why it is an INSERT and not a dispatch ────────
     *
     * `EventEmitter::emit` is the outbox write itself, called inside the
     * same transaction as the attempt row — see ProgressController's
     * docblock and StartUp.md §10. `training.passed` / `training.failed`
     * are written as single-quoted literals on purpose: `ModuleRegistryTest`
     * is bidirectional (ModuleServiceProvider's own docblock on this
     * module), and the literal is what flips `isRaised: true` for both.
     */
    public function submit(Request $request, string $test): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLearner($user);

        $scope = ViewScope::forUser($user);

        // Same 404-for-all-three shape as [showTest]: a sibling franchise's
        // test, a draft, or one that does not exist come back identically.
        $row = InventoryVisibility::constrain(
            TrainingTest::query()->where('status', TrainingTest::PUBLISHED),
            $user,
            $scope,
        )->findOrFail($test);

        // present rather than required: an empty test is a real, gradeable
        // shape (decision 3) and a client submitting against one sends an
        // empty array — required would reject that as though the key were
        // missing, when it is deliberately empty.
        $input = $request->validate([
            'answers' => ['present', 'array'],
            'answers.*' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($row->requires_guide && $row->guide_id !== null && ! $this->guideFinishedBy($user, $row->guide_id)) {
            Refusal::trainingGuideNotFinished()->authorize();
        }

        $attempt = DB::transaction(function () use ($user, $row, $input) {
            $lockedTest = TrainingTest::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedTest->max_attempts !== null) {
                // Fifth-option brief: `count_attempts_after_pass` decides
                // WHICH attempts this count includes, never whether the lock
                // or the count-then-compare shape above them changes — see
                // this method's own docblock, "The retake cap is a race".
                // `true` (today's behaviour) is the same plain COUNT(*) this
                // always was; `false` (the default) asks LapsedPasses for
                // the one definition of "taken while a pass was still
                // valid" instead of a second copy of it here.
                $taken = $lockedTest->count_attempts_after_pass
                    ? TrainingAttempt::query()
                        ->where('user_id', $user->getKey())
                        ->where('test_id', $lockedTest->getKey())
                        ->count()
                    : LapsedPasses::attemptsCountingTowardCap($user->getKey(), $lockedTest->getKey());

                if ($taken >= $lockedTest->max_attempts) {
                    Refusal::trainingAttemptCapReached($lockedTest->max_attempts)->authorize();
                }
            }

            $result = TestGrader::grade($lockedTest, $input['answers']);

            $attempt = TrainingAttempt::create([
                'user_id' => $user->getKey(),
                'test_id' => $lockedTest->getKey(),
                'answers' => $input['answers'],
                'score' => $result['score'],
                'passed' => $result['passed'],
                'taken_at' => now(),
                'expires_on' => ($result['passed'] && $lockedTest->valid_for_days !== null)
                    ? now()->addDays($lockedTest->valid_for_days)->toDateString()
                    : null,
            ]);

            // Deliberately NOT 'answers' — §9 ACTIVITY personal data, and the
            // published trigger description promises only "the score and,
            // when the test expires, the date the pass runs out." A
            // `webhook` action ships this payload verbatim to a URL the
            // tenant chose, and AutomationsController surfaces payload
            // values through condition_trace.actual — either would leak a
            // named person's individual answers off a sentence that never
            // said it would. The attempt row itself still holds them, for
            // the Dashboard.
            $this->events->emit($result['passed'] ? 'training.passed' : 'training.failed', $attempt, [
                'after' => [
                    'test_id' => $lockedTest->getKey(),
                    'user_id' => $user->getKey(),
                    'score' => $attempt->score,
                    'passed' => $attempt->passed,
                    'expires_on' => $attempt->expires_on?->toDateString(),
                ],
            ]);

            return $attempt;
        });

        return response()->json([
            'score' => $attempt->score,
            'passed' => $attempt->passed,
            'expires_on' => $attempt->expires_on?->toDateString(),
        ]);
    }

    /** Whether THIS learner has finished THIS guide — never a blanket "does anybody". */
    private function guideFinishedBy(User $user, string $guideId): bool
    {
        return TrainingProgress::query()
            ->where('user_id', $user->getKey())
            ->where('guide_id', $guideId)
            ->whereNotNull('completed_at')
            ->exists();
    }

    private function authorizeLearner(User $user): void
    {
        if (! $user->hasPermission('training.view')) {
            Refusal::permission('see your training')->authorize();
        }
    }

    /**
     * Every published guide this learner's view scope reaches, keyed by id
     * — the same InventoryVisibility engine the authoring lists use
     * (GuidesController::index), pointed at the learner's own view. The one
     * query both [library] and [assigned] read guides from; see this
     * class's docblock for why `assigned` does not run its own.
     */
    private function readableGuides(User $user, ViewScope $scope): Collection
    {
        return InventoryVisibility::constrain(
            TrainingGuide::query()->where('status', TrainingGuide::PUBLISHED),
            $user,
            $scope,
        )->orderBy('title')->get()->keyBy('id');
    }

    /** Same as {@see readableGuides}, for tests. */
    private function readableTests(User $user, ViewScope $scope): Collection
    {
        return InventoryVisibility::constrain(
            TrainingTest::query()->where('status', TrainingTest::PUBLISHED),
            $user,
            $scope,
        )->orderBy('title')->get()->keyBy('id');
    }

    /**
     * @param  Collection<string, TrainingGuide>  $guides
     * @param  Collection<string, TrainingTest>  $tests
     * @param  list<string>  $lapsedTestIds  see this class's own docblock, "A lapsed pass reappears here"
     * @param  Collection<string, TrainingProgress>  $guideProgress  see [guideProgress]
     * @param  Collection<string, TrainingAttempt>  $lastAttempts  see [lastAttempts]
     * @param  Collection<string, int>  $attemptCounts  see [attemptCounts]
     * @param  Collection<string, int>  $attemptsTowardCap  see [attemptsTowardCap]
     * @return array<int, array<string, mixed>>
     */
    private function library(
        Collection $guides,
        Collection $tests,
        array $lapsedTestIds,
        Collection $guideProgress,
        Collection $lastAttempts,
        Collection $attemptCounts,
        Collection $attemptsTowardCap,
    ): array {
        return $guides->map(fn (TrainingGuide $g) => $this->presentGuide($g, $guideProgress->get($g->id)))
            ->concat($tests->map(
                fn (TrainingTest $t) => $this->presentTest(
                    $t, $lapsedTestIds, $lastAttempts->get($t->id), $attemptCounts->get($t->id, 0),
                    $attemptsTowardCap->get($t->id, 0),
                )
            ))
            ->values()
            ->all();
    }

    /**
     * The union decision 2 spells out: assigned to me by name, or assigned
     * to the one role I hold — never the other way around. A role
     * assignment is never expanded into per-user rows, which is what lets
     * adding somebody to a role hand them this with no sync job that can go
     * stale; the union runs at READ time instead.
     *
     * A real query against the real `training_assignments` table — nothing
     * writes to it before Task 9, so this returns empty for now, but the
     * query itself does not change when Task 9 starts writing rows.
     *
     * Rendered from `$guides`/`$tests` — the same place-scoped,
     * published-only collections [library] uses — never from
     * `$assignment->guide` or `$assignment->test` directly; see this
     * class's docblock.
     *
     * @param  Collection<string, TrainingGuide>  $guides
     * @param  Collection<string, TrainingTest>  $tests
     * @param  list<string>  $lapsedTestIds  see this class's own docblock, "A lapsed pass reappears here"
     * @param  Collection<string, TrainingProgress>  $guideProgress  see [guideProgress]
     * @param  Collection<string, TrainingAttempt>  $lastAttempts  see [lastAttempts]
     * @param  Collection<string, int>  $attemptCounts  see [attemptCounts]
     * @param  Collection<string, int>  $attemptsTowardCap  see [attemptsTowardCap]
     * @return array<int, array<string, mixed>>
     */
    private function assigned(
        User $user,
        Collection $guides,
        Collection $tests,
        array $lapsedTestIds,
        Collection $guideProgress,
        Collection $lastAttempts,
        Collection $attemptCounts,
        Collection $attemptsTowardCap,
    ): array {
        $rawAssignments = $this->assignedQuery($user)->get();

        $rows = $rawAssignments
            ->map(fn (TrainingAssignment $a) => $this->presentAssignment(
                $a, $guides, $tests, $lapsedTestIds, $guideProgress, $lastAttempts, $attemptCounts, $attemptsTowardCap,
            ))
            ->filter()
            ->values();

        // The exact same resolution [presentAssignment] itself applies —
        // test tried first — so "already shown by a real assignment row"
        // can never drift from what was actually rendered above.
        $alreadyShownTestIds = $rawAssignments
            ->filter(fn (TrainingAssignment $a) => $a->test_id !== null && $tests->has($a->test_id))
            ->pluck('test_id')
            ->all();

        foreach (array_diff($lapsedTestIds, $alreadyShownTestIds) as $testId) {
            // No real assignment row backs this one — there is nothing to
            // name here, unlike a genuine row's own `assignment_id`/`due_on`.
            $rows->push([
                'assignment_id' => null,
                'due_on' => null,
                'assignee_type' => null,
                ...$this->presentTest(
                    $tests->get($testId), $lapsedTestIds, $lastAttempts->get($testId), $attemptCounts->get($testId, 0),
                    $attemptsTowardCap->get($testId, 0),
                ),
            ]);
        }

        return $rows->values()->all();
    }

    /**
     * This learner's own currently-lapsed passes, among the tests THIS read
     * can even show — published, place-visible ($tests, the same
     * InventoryVisibility-constrained collection [library] and [assigned]
     * both draw from). See this class's own docblock, "A lapsed pass
     * reappears here", for why `assigned` is where this surfaces and how it
     * avoids a second row for a test a real assignment already shows.
     *
     * `LapsedPasses::currentlyLapsedQuery` — never a second, hand-rolled
     * "passed AND expires_on <= today" here — is the identical definition
     * `ExpireTrainingPasses`'s nightly sweep and `DashboardController`'s
     * `retaking` count both read, so a test cannot reappear here while the
     * Dashboard disagrees about whether it has lapsed.
     *
     * @return list<string>
     */
    private function lapsedTestIds(User $user, Collection $tests): array
    {
        if ($tests->isEmpty()) {
            return [];
        }

        return LapsedPasses::currentlyLapsedQuery($tests->keys()->all())
            ->where('training_attempts.user_id', $user->getKey())
            ->pluck('training_attempts.test_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * This learner's own progress row for every guide THIS read can even
     * show — one query, batched against `$guides->keys()`, never one per
     * card the way [guideFinishedBy] queries one guide at a time for the
     * `submit` gate check. `(user_id, guide_id)` is unique
     * (`ProgressController::seen`'s own `insertOrIgnore`), so keying by
     * `guide_id` can never collide two rows onto one key.
     *
     * @param  Collection<string, TrainingGuide>  $guides
     * @return Collection<string, TrainingProgress>
     */
    private function guideProgress(User $user, Collection $guides): Collection
    {
        if ($guides->isEmpty()) {
            return collect();
        }

        return TrainingProgress::query()
            ->where('user_id', $user->getKey())
            ->whereIn('guide_id', $guides->keys())
            ->get()
            ->keyBy('guide_id');
    }

    /**
     * This learner's own MOST RECENT attempt at every test THIS read can
     * even show — whatever its outcome, pass or fail. A different question
     * from [lapsedTestIds]/`LapsedPasses::currentStandingQuery`: that one
     * asks "which PASSED attempt is this person's current standing", and a
     * fail after it would not change the answer. This one asks "what did
     * they do most recently", which a `presentTest` card needs regardless
     * of whether it passed — "Passed last year" and a failed retake both
     * come off the same field.
     *
     * One window-function query, batched against `$tests->keys()` — the
     * same one-query-per-collection-read shape [lapsedTestIds] already
     * uses, never N+1 per card. `taken_at` DESC then `id` DESC as the
     * tiebreak, matching `LapsedPasses::currentStandingQuery`'s own
     * ordering for the identical reason: two attempts inserted in the same
     * transaction can share a `taken_at`.
     *
     * @param  Collection<string, TrainingTest>  $tests
     * @return Collection<string, TrainingAttempt>
     */
    private function lastAttempts(User $user, Collection $tests): Collection
    {
        if ($tests->isEmpty()) {
            return collect();
        }

        $latest = TrainingAttempt::query()
            ->select(['id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY test_id ORDER BY taken_at DESC, id DESC) AS rn')
            ->where('user_id', $user->getKey())
            ->whereIn('test_id', $tests->keys());

        return TrainingAttempt::query()
            ->joinSub($latest, 'latest_attempt', function ($join) {
                $join->on('training_attempts.id', '=', 'latest_attempt.id');
            })
            ->where('latest_attempt.rn', 1)
            ->get()
            ->keyBy('test_id');
    }

    /**
     * This learner's own COUNT of attempts at every test THIS read can even
     * show — the field the review that opened task-13's report flagged as
     * missing: `last_taken_at` only ever says "at least one, or none", which
     * cannot tell one prior attempt from several.
     *
     * Batched once per request against `$tests->keys()`, the same
     * one-query-per-collection-read shape [lastAttempts] and [lapsedTestIds]
     * already use — never N+1 per card.
     *
     * The plain historical total, unconditionally — never split by
     * `count_attempts_after_pass`. `TrainingLearnProgressFieldsTest`'s own
     * contract for this field is "the exact number of attempts taken",
     * full stop; see [attemptsTowardCap] for the SEPARATE, fifth-option-aware
     * number `_capReached` (test_player.dart) actually gates Submit on since
     * the fifth-option brief — the two can legitimately disagree (a passer's
     * free retake still shows up here, in the count of what they DID, even
     * on a test where it never touched their `max_attempts`), and conflating
     * them was tried and reverted — see [attemptsTowardCap]'s own docblock.
     *
     * @param  Collection<string, TrainingTest>  $tests
     * @return Collection<string, int>
     */
    private function attemptCounts(User $user, Collection $tests): Collection
    {
        if ($tests->isEmpty()) {
            return collect();
        }

        return TrainingAttempt::query()
            ->select('test_id')
            ->selectRaw('COUNT(*) AS taken')
            ->where('user_id', $user->getKey())
            ->whereIn('test_id', $tests->keys())
            ->groupBy('test_id')
            ->get()
            ->keyBy('test_id')
            ->map(fn ($row) => (int) $row->taken);
    }

    /**
     * The SEPARATE number `_capReached` (test_player.dart) actually gates
     * Submit on, since the fifth-option brief (2026-09-09) — never
     * [attemptCounts]'s plain historical total, which a passer's free retake
     * still raises even on a test where it spends none of their
     * `max_attempts`. A client comparing THAT number against `max_attempts`
     * would grey out Submit for exactly the person this option exists to
     * free — with `max_attempts: 1`, a single pass already raises the plain
     * total to 1, and would stay stuck there forever if this were the number
     * compared, which is the reported bug reappearing one layer up, in the
     * client's own gate instead of the server's.
     *
     * Split by each test's OWN `count_attempts_after_pass` rather than one
     * query for the whole set: a `true` test keeps the plain grouped
     * `COUNT(*)` [attemptCounts] itself uses; a `false` test needs
     * `LapsedPasses::attemptsCountingTowardCapBatch`'s definition instead,
     * the SAME one `showTest`/`submit` read off a single test, so this
     * batched number can never disagree with what either of those would say
     * about the identical (user, test) pair. Two queries at most — never one
     * per test — whatever the mix of the two settings in `$tests`.
     *
     * @param  Collection<string, TrainingTest>  $tests
     * @return Collection<string, int>
     */
    private function attemptsTowardCap(User $user, Collection $tests): Collection
    {
        if ($tests->isEmpty()) {
            return collect();
        }

        $strictIds = $tests->filter(fn (TrainingTest $t) => $t->count_attempts_after_pass)->keys()->all();
        $lenientIds = $tests->reject(fn (TrainingTest $t) => $t->count_attempts_after_pass)->keys()->all();

        $strictCounts = $strictIds === [] ? collect() : TrainingAttempt::query()
            ->select('test_id')
            ->selectRaw('COUNT(*) AS taken')
            ->where('user_id', $user->getKey())
            ->whereIn('test_id', $strictIds)
            ->groupBy('test_id')
            ->get()
            ->keyBy('test_id')
            ->map(fn ($row) => (int) $row->taken);

        $lenientCounts = LapsedPasses::attemptsCountingTowardCapBatch($user->getKey(), $lenientIds);

        return $strictCounts->merge($lenientCounts);
    }

    /**
     * [attemptsTowardCap], for a SINGLE (user, test) pair — `showTest`'s own
     * read, same split as the batched version for the identical reason:
     * `count_attempts_after_pass` true keeps a plain `COUNT(*)`, false asks
     * `LapsedPasses::attemptsCountingTowardCap` instead.
     */
    private function attemptsTowardCapForOne(User $user, TrainingTest $test): int
    {
        if ($test->count_attempts_after_pass) {
            return TrainingAttempt::query()
                ->where('user_id', $user->getKey())
                ->where('test_id', $test->id)
                ->count();
        }

        return LapsedPasses::attemptsCountingTowardCap($user->getKey(), $test->id);
    }

    /** Just the assignee union (decision 2). Place scope and publication are decided in [presentAssignment]. */
    private function assignedQuery(User $user): Builder
    {
        return TrainingAssignment::query()
            ->where(function (Builder $outer) use ($user): void {
                $outer->where(function (Builder $mine) use ($user): void {
                    $mine->where('assignee_type', TrainingAssignment::USER)
                        ->where('assignee_id', $user->getKey());
                });

                if ($user->role_id !== null) {
                    $outer->orWhere(function (Builder $mine) use ($user): void {
                        $mine->where('assignee_type', TrainingAssignment::ROLE)
                            ->where('assignee_id', $user->role_id);
                    });
                }
            });
    }

    /**
     * Whether this row is shown at all, and what it is shown AS, are the
     * SAME decision: look the row's target up in the readable, published
     * collection for its kind, and render whichever one is found there. A
     * row whose target is not in either collection — outside this learner's
     * place scope, still a draft, or (both nullable, per the migration)
     * simply pointing at nothing — is excluded, `null`.
     *
     * `test` is tried first when a row names both: the schema does not
     * forbid a row carrying both a `guide_id` and a `test_id` (see the
     * migration), even though no writer this codebase has today produces
     * one. Trying test first is an arbitrary but STABLE choice for that
     * edge case — the point this method exists to guarantee is that
     * whichever target is chosen is the one both checked and rendered,
     * never one checked and the other rendered.
     *
     * @param  Collection<string, TrainingGuide>  $guides
     * @param  Collection<string, TrainingTest>  $tests
     * @param  list<string>  $lapsedTestIds  see this class's own docblock, "A lapsed pass reappears here"
     * @param  Collection<string, TrainingProgress>  $guideProgress  see [guideProgress]
     * @param  Collection<string, TrainingAttempt>  $lastAttempts  see [lastAttempts]
     * @param  Collection<string, int>  $attemptCounts  see [attemptCounts]
     * @param  Collection<string, int>  $attemptsTowardCap  see [attemptsTowardCap]
     * @return array<string, mixed>|null
     */
    private function presentAssignment(
        TrainingAssignment $assignment,
        Collection $guides,
        Collection $tests,
        array $lapsedTestIds,
        Collection $guideProgress,
        Collection $lastAttempts,
        Collection $attemptCounts,
        Collection $attemptsTowardCap,
    ): ?array {
        $content = match (true) {
            $assignment->test_id !== null && $tests->has($assignment->test_id)
                => $this->presentTest(
                    $tests->get($assignment->test_id),
                    $lapsedTestIds,
                    $lastAttempts->get($assignment->test_id),
                    $attemptCounts->get($assignment->test_id, 0),
                    $attemptsTowardCap->get($assignment->test_id, 0),
                ),
            $assignment->guide_id !== null && $guides->has($assignment->guide_id)
                => $this->presentGuide($guides->get($assignment->guide_id), $guideProgress->get($assignment->guide_id)),
            default => null,
        };

        if ($content === null) {
            return null;
        }

        return [
            'assignment_id' => $assignment->id,
            'due_on' => $assignment->due_on?->toDateString(),
            'assignee_type' => $assignment->assignee_type,
            ...$content,
        ];
    }

    /**
     * `last_screen`/`completed_at` are this learner's own reading position
     * — the SAME row `ProgressController::seen` writes and
     * `guideFinishedBy` already reads, off `$progress`, batched once per
     * request by [guideProgress] rather than queried per card. `null` for
     * both when there is no row at all: a guide never opened, exactly the
     * shape `seen()`'s own response gives a guide that has not been
     * touched yet.
     *
     * @return array<string, mixed>
     */
    private function presentGuide(TrainingGuide $guide, ?TrainingProgress $progress = null): array
    {
        return [
            'type' => 'guide',
            'id' => $guide->id,
            'title' => $guide->title,
            'summary' => $guide->summary,
            'company_id' => $guide->company_id,
            'franchise_id' => $guide->franchise_id,
            'screen_count' => $guide->screenCount(),
            // Images the guide holds itself get a signed link, minted here
            // because this is where the learner's read was authorised.
            'screens' => TrainingMediaLinks::resolve($guide->screens ?? [], $guide),
            'last_screen' => $progress?->last_screen,
            'completed_at' => $progress?->completed_at?->toIso8601String(),
            // When this person last completed a topic of it — what a "Continue"
            // card picks between two half-read guides by.
            'last_read_at' => $progress?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Every field a raw {@see TestsController::present} would show, except
     * `questions` — which never leaves this class as `$test->questions`.
     * It is always the return of {@see LearnerView::stripAnswerKey}, here and
     * everywhere else in this controller that mentions a test.
     *
     * `due_for_retaking` — never "due for re-certification" or any other
     * spelling carrying the word this module's output must never contain
     * (see this class's own docblock) — is this learner's own current lapse
     * state for THIS test, off the same `$lapsedTestIds` set every caller of
     * this method already computed once in [index]; see "A lapsed pass
     * reappears here" above for why it is a field here rather than a
     * separately-shaped entry.
     *
     * `last_score`/`last_passed`/`last_taken_at` are this learner's own most
     * recent attempt at this test, whatever its outcome — batched once per
     * request by [lastAttempts] rather than queried per card, and passed in
     * as `$lastAttempt` rather than looked up here. `null` for all three
     * when there is no attempt at all: a test never taken.
     *
     * `attempts_taken` (task-13 review) is the exact count of this
     * learner's own attempts at this test — batched once per request by
     * [attemptCounts], same shape as `$lastAttempt`. Added because
     * `last_taken_at` alone cannot tell one prior attempt from several. This
     * is the plain historical total, unconditionally — it does NOT say
     * whether the next attempt is actually blocked; see `attempts_toward_cap`
     * just below for that.
     *
     * `attempts_toward_cap` (fifth-option brief, 2026-09-09) is the SEPARATE
     * number a client must actually gate Submit on — batched once per
     * request by [attemptsTowardCap], that method's own docblock. Equal to
     * `attempts_taken` on a `count_attempts_after_pass: true` test (every
     * attempt counts, same as before this option existed), but can read
     * LOWER on a `false` one: a retake taken while an existing pass is still
     * valid raised `attempts_taken` (it genuinely happened) without spending
     * a `max_attempts` slot, and this is 0 whenever the person currently
     * holds a still-good pass, however many attempts they have actually
     * made — see `LapsedPasses::walkCountingTowardCap`'s own docblock for
     * why 0, not merely "one fewer". A client comparing `attempts_taken`
     * here instead would grey out Submit for exactly the person this option
     * exists to free.
     *
     * @param  list<string>  $lapsedTestIds
     * @return array<string, mixed>
     */
    private function presentTest(
        TrainingTest $test,
        array $lapsedTestIds = [],
        ?TrainingAttempt $lastAttempt = null,
        int $attemptsTaken = 0,
        int $attemptsTowardCap = 0,
    ): array {
        return [
            'type' => 'test',
            'id' => $test->id,
            'title' => $test->title,
            'company_id' => $test->company_id,
            'franchise_id' => $test->franchise_id,
            'guide_id' => $test->guide_id,
            'requires_guide' => $test->requires_guide,
            'pass_mark' => $test->pass_mark,
            'max_attempts' => $test->max_attempts,
            'attempts_taken' => $attemptsTaken,
            'attempts_toward_cap' => $attemptsTowardCap,
            'valid_for_days' => $test->valid_for_days,
            'count_attempts_after_pass' => $test->count_attempts_after_pass,
            'due_for_retaking' => in_array($test->id, $lapsedTestIds, true),
            'question_count' => $test->questionCount(),
            'questions' => LearnerView::stripAnswerKey($test->questions ?? []),
            'last_score' => $lastAttempt?->score,
            'last_passed' => $lastAttempt?->passed,
            'last_taken_at' => $lastAttempt?->taken_at?->toIso8601String(),
        ];
    }
}
