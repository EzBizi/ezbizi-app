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
use App\Support\Access\ScopeReach;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Training\LapsedPasses;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * How an organization watches training — the dashboard reads, and the
 * endpoint HR renders from (training task-11 brief).
 *
 * Gated on `training.monitor`, the fourth of the module's four permissions
 * (TrainingGuidePolicy's docblock: "monitor sees other people's results,
 * manage writes the guides and the tests, assign gives one to somebody") —
 * never `.manage`, and never `.view` (the learner tier LearnController and
 * ProgressController gate on). Watching everyone else's results is a
 * different act from either authoring content or learning it yourself.
 *
 * ── Two different reach questions, asked of two different things ────
 *
 * Content scope decides which guides and tests appear as ROWS —
 * `InventoryVisibility::constrain`, applied to the GUIDE or TEST itself, the
 * same engine `GuidesController::index` and
 * `LearnController::readableGuides`/`readableTests` already use. A guide
 * outside the monitor's own reach never appears as a row at all.
 *
 * But `records`, `people`, and every count this controller returns disclose
 * a PERSON — their score, what they failed, when their pass lapsed. That is
 * a different question from "may you see this guide", and content scope
 * answers it wrongly for exactly the case that matters most: an
 * organization-wide guide or test is visible to everybody, which makes
 * content scope constrain NOTHING for it at all. A Riverside-only monitor
 * watching an organization-wide test must still never see, count, or be
 * handed the name of a Northgate-only learner's result on it.
 *
 * So person-level reads are bounded by reach over the PERSON, never by reach
 * over the content: `ScopeReach::constrainToVisiblePeople` — [$reach] below
 * — decides whose progress is counted in `assigned`/`overdue`/`retaking`/
 * `people_*`, and whose name `people` and `records` may return. Two monitors
 * at different franchises watching the same organization-wide guide see the
 * SAME rows (content scope does not vary) but DIFFERENT counts and
 * DIFFERENT names on the pane and on `records` (person reach does) — the
 * inverse of what an earlier version of this docblock claimed, which is
 * corrected here rather than left standing next to code that disagrees with
 * it (task-11 review, Important 3).
 *
 * `AssignmentReach::mayAssignTo` asks the identical question on the write
 * side — may this assigner reach that assignee — and is built on the same
 * `ScopeReach` primitive so read and write cannot drift apart.
 *
 * ── One word never appears in this module's output ──────────────────
 *
 * "Certification" belongs to HR's `ExpiringCertifications` widget — a
 * 60-day lookahead over licence dates typed at onboarding, the EMPLOYEE'S to
 * renew. This controller's `retaking` counts our own test passes lapsing —
 * OURS to re-assign, a different table, a different fact, a different
 * person responsible for acting on it. The two panels sit on the same
 * screen; using the same word for both would make them agree when they do
 * not. See `TrainingDashboardTest::test_the_dashboard_never_says_certification`.
 *
 * ── `retaking` has no lookahead, on purpose ──────────────────────────
 *
 * `people_due_for_retaking` (and a row's own `retaking`) means ALREADY
 * LAPSED — `expires_on <= today`, via `LapsedPasses`, the identical
 * definition `ExpireTrainingPasses`'s nightly sweep uses. Never "lapsing
 * soon". HR's panel is a 60-day WARNING; this one is a failure already in
 * progress, and making the two symmetric (both a lookahead) would hide
 * exactly the thing that makes them different facts for different people to
 * act on. If a lookahead is ever wanted here, it is a NEW field —
 * `retaking_soon`, say — never a redefinition of this one.
 *
 * ── The summary counts PEOPLE, never assignments ─────────────────────
 *
 * A client that sums this response's per-row `overdue`/`retaking` counts is
 * counting ASSIGNMENTS, not people: one person overdue on three guides sums
 * to 3, and a home-screen glance saying "3 overdue" sends somebody looking
 * for three people who do not exist. De-duplicating a person across guides
 * needs the join the client does not have, so `summary` — `people_assigned`,
 * `people_overdue`, `people_due_for_retaking` — is built by UNIONING the
 * same per-row person sets (associative arrays keyed by user id) this
 * controller already builds the rows from, rather than summing the rows'
 * own counts. See [index]'s own body: the union happens in the SAME loop
 * that builds each row, off the SAME sets, so the two can never disagree
 * about who is in them.
 *
 * ── Scale: nine queries, however many guides/tests/people exist ─────
 *
 * [index] issues exactly nine queries regardless of how many guides, tests,
 * or people the organization has — re-measured after adding [$reach]
 * against 40 guides + 40 tests, 60 people (see the task-11 report for the
 * literal counts and the SQL):
 *
 *   1. Visible, published guides            (InventoryVisibility)
 *   2. Visible, published tests             (InventoryVisibility)
 *   3. Every assignment targeting any of them, in ONE query — never one
 *      query per guide/test — followed by a role-membership lookup for
 *      only the role ids that assignment set actually named.
 *   4. Active users holding one of those roles (skipped entirely when no
 *      assignment names a role).
 *   5. Every `training_progress` row for the visible guides, in one query.
 *   6. Every test's CURRENT standing (`LapsedPasses::currentStandingQuery`,
 *      one window-function query for every visible test at once) plus one
 *      more query for "has this person ever attempted this test at all"
 *      (needed for `in_progress`, which a person who only ever failed would
 *      otherwise not appear in anywhere).
 *   7-9. [$reach]'s person-reach filter — `ScopeReach::constrainToVisiblePeople`,
 *      asked ONCE per request (memoized inside `ScopeReach` after that) over
 *      the union of every person id gathered above, never once per guide,
 *      per test, or per person. Three queries, not one: this monitor's own
 *      reachable companies, their own reachable franchises, and the `WHERE
 *      id IN (...)` filter itself — `ScopeReach` computes its answer
 *      independently of `InventoryVisibility`'s own (already flat, already
 *      pre-existing) company/franchise lookups above, which is a real
 *      redundancy between the two engines rather than a mistake in either;
 *      collapsing it is out of scope for this task.
 *
 * That is ten, not nine, when a role is named — the count grows with the
 * number of DISTINCT queries this method issues, never with the number of
 * rows or people it returns; every one of the nine (ten with a role) selects
 * a set, and every subsequent step is a PHP-side group-by (or intersect)
 * over an already-fetched collection, not a loop that queries again per
 * guide, per test, or per person.
 */
class DashboardController extends Controller
{
    private const PASSED = 'passed';

    private const RETAKING = 'retaking';

    private const OVERDUE = 'overdue';

    private const IN_PROGRESS = 'in_progress';

    public function __construct(private readonly ScopeReach $reach) {}

    /** GET /api/v1/training/dashboard */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeMonitor($user);

        $scope = ViewScope::forUser($user);
        $guides = $this->visibleGuides($user, $scope);
        $tests = $this->visibleTests($user, $scope);

        $guideIds = $guides->keys()->all();
        $testIds = $tests->keys()->all();

        $assignments = $this->assignmentAudience($guideIds, $testIds);
        $guideActivity = $this->guideActivity($guideIds);
        $testActivity = $this->testActivity($testIds);

        // Content scope (above) decided which guides/tests are ROWS. Person
        // reach decides whose progress may be COUNTED in them — see the
        // class docblock, "Two different reach questions, asked of two
        // different things". Asked ONCE over the union of every person id
        // gathered so far, never once per guide/test/person.
        $reachable = $this->reachablePeople(
            $this->allPeopleIds($assignments, $guideActivity, $testActivity),
            $user,
        );

        $rows = [];
        $peopleAssigned = [];
        $peopleOverdue = [];
        $peopleRetaking = [];

        foreach ($guides as $guide) {
            $states = $this->statesFor(
                assigned: array_intersect_key($assignments['guide'][$guide->id]['assigned'] ?? [], $reachable),
                overdueCandidates: array_intersect_key($assignments['guide'][$guide->id]['overdue_candidates'] ?? [], $reachable),
                passed: array_intersect_key($guideActivity[$guide->id]['passed'] ?? [], $reachable),
                retaking: [],
                started: array_intersect_key($guideActivity[$guide->id]['started'] ?? [], $reachable),
            );

            $rows[] = $this->row($guide->id, $guide->title, $states, 'guide');
            $peopleAssigned += $states['assigned'];
            $peopleOverdue += $states[self::OVERDUE];
            $peopleRetaking += $states[self::RETAKING];
        }

        foreach ($tests as $test) {
            $states = $this->statesFor(
                assigned: array_intersect_key($assignments['test'][$test->id]['assigned'] ?? [], $reachable),
                overdueCandidates: array_intersect_key($assignments['test'][$test->id]['overdue_candidates'] ?? [], $reachable),
                passed: array_intersect_key($testActivity[$test->id]['passed'] ?? [], $reachable),
                retaking: array_intersect_key($testActivity[$test->id]['retaking'] ?? [], $reachable),
                started: array_intersect_key($testActivity[$test->id]['attempted'] ?? [], $reachable),
            );

            $rows[] = $this->row($test->id, $test->title, $states, 'test');
            $peopleAssigned += $states['assigned'];
            $peopleOverdue += $states[self::OVERDUE];
            $peopleRetaking += $states[self::RETAKING];
        }

        return response()->json([
            'data' => $rows,
            'summary' => [
                'people_assigned' => count($peopleAssigned),
                'people_overdue' => count($peopleOverdue),
                'people_due_for_retaking' => count($peopleRetaking),
            ],
        ]);
    }

    /**
     * GET /api/v1/training/dashboard/{id}/people
     *
     * The pane rows for ONE guide or test: every person in its audience
     * (assigned to it, or with any activity against it) and their state.
     */
    public function people(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->authorizeMonitor($user);

        $scope = ViewScope::forUser($user);

        [$kind, $item] = $this->findVisibleItem($id, $user, $scope);

        if ($kind === 'guide') {
            $assignments = $this->assignmentAudience([$item->id], []);
            $activity = $this->guideActivity([$item->id]);

            $assigned = $assignments['guide'][$item->id]['assigned'] ?? [];
            $overdueCandidates = $assignments['guide'][$item->id]['overdue_candidates'] ?? [];
            $passed = $activity[$item->id]['passed'] ?? [];
            $retaking = [];
            $started = $activity[$item->id]['started'] ?? [];
            $dueOnByUser = $assignments['guide'][$item->id]['due_on'] ?? [];
        } else {
            $assignments = $this->assignmentAudience([], [$item->id]);
            $activity = $this->testActivity([$item->id]);

            $assigned = $assignments['test'][$item->id]['assigned'] ?? [];
            $overdueCandidates = $assignments['test'][$item->id]['overdue_candidates'] ?? [];
            $passed = $activity[$item->id]['passed'] ?? [];
            $retaking = $activity[$item->id]['retaking'] ?? [];
            $started = $activity[$item->id]['attempted'] ?? [];
            $dueOnByUser = $assignments['test'][$item->id]['due_on'] ?? [];
        }

        // Content scope ([findVisibleItem]) already decided this ITEM may be
        // a row at all. Person reach still has to decide who among its
        // audience may be COUNTED and NAMED — see the class docblock, "Two
        // different reach questions, asked of two different things". An
        // organization-wide item is visible to every monitor, so content
        // scope alone would disclose every person against it regardless of
        // where they stand.
        $reachable = $this->reachablePeople(
            array_keys($assigned + $overdueCandidates + $passed + $retaking + $started),
            $user,
        );

        $assigned = array_intersect_key($assigned, $reachable);
        $overdueCandidates = array_intersect_key($overdueCandidates, $reachable);
        $passed = array_intersect_key($passed, $reachable);
        $retaking = array_intersect_key($retaking, $reachable);
        $started = array_intersect_key($started, $reachable);

        $states = $this->statesFor($assigned, $overdueCandidates, $passed, $retaking, $started);

        $audience = $assigned + $passed + $retaking + $started;
        $users = User::query()->whereIn('id', array_keys($audience))->get(['id', 'name'])->keyBy('id');

        $detail = $kind === 'guide'
            ? $this->guideDetailByUser($item->id, array_keys($audience))
            : $this->testDetailByUser($item->id, array_keys($audience));

        $rows = collect(array_keys($audience))
            ->map(function (string $userId) use ($states, $users, $detail, $dueOnByUser) {
                return [
                    'user_id' => $userId,
                    'name' => $users->get($userId)?->name,
                    'state' => $this->stateOf($userId, $states),
                    'due_on' => $dueOnByUser[$userId] ?? null,
                    ...($detail[$userId] ?? []),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/v1/training/records?user=
     *
     * One person's training — for HR's screen to render. Training writes
     * NOTHING into HR's tables (spec decision 7); this is the read HR's
     * controller calls instead.
     */
    public function records(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeMonitor($user);

        $input = $request->validate([
            'user' => ['required', 'uuid', Rule::exists('users', 'id')
                ->where('organization_id', $user->organization_id)],
        ]);

        $subject = User::findOrFail($input['user']);

        // The validation above only proves $subject is in the same TENANT —
        // a tenant check, not a reach check (task-11 review, Critical 1).
        // Content scope on the guides/tests below decides which of the
        // subject's rows may appear; it says nothing about whether this
        // monitor may be handed a person's name and results at all. See the
        // class docblock, "Two different reach questions, asked of two
        // different things".
        if (! $this->canReachPerson($subject, $user)) {
            $this->refusePersonReach($user)->authorize();
        }

        $scope = ViewScope::forUser($user);
        $guides = $this->visibleGuides($user, $scope);
        $tests = $this->visibleTests($user, $scope);

        $guideIds = $guides->keys()->all();
        $testIds = $tests->keys()->all();

        $assignedGuideIds = $this->assignedTargetIds($subject, $guideIds, 'guide_id');
        $assignedTestIds = $this->assignedTargetIds($subject, $testIds, 'test_id');

        $progress = TrainingProgress::query()
            ->where('user_id', $subject->getKey())
            ->whereIn('guide_id', $guideIds)
            ->get(['guide_id', 'last_screen', 'completed_at'])
            ->keyBy('guide_id');

        $standing = LapsedPasses::currentStandingQuery($testIds)
            ->where('training_attempts.user_id', $subject->getKey())
            ->get(['training_attempts.test_id', 'training_attempts.score', 'training_attempts.passed', 'training_attempts.expires_on'])
            ->keyBy('test_id');

        // The whole history, same ruling as the pane's (owner, "all"). Kept
        // here as well as there because these two reads answer the SAME
        // question about the same person on the same day — the in-tab pane
        // and HR's record card — and one of them showing three attempts
        // while the other shows one is the drift this module has spent its
        // life avoiding. HR's card is not built yet, so it is cheaper to
        // ship the shape now than to change it under a consumer later.
        $historyByTest = $testIds === [] ? collect() : TrainingAttempt::query()
            ->where('user_id', $subject->getKey())
            ->whereIn('test_id', $testIds)
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->get(['test_id', 'score', 'passed', 'taken_at', 'expires_on'])
            ->groupBy('test_id');

        $today = now()->toDateString();

        $data = [];

        foreach ($guides as $guide) {
            $isAssigned = in_array($guide->id, $assignedGuideIds, true);
            $p = $progress->get($guide->id);

            if (! $isAssigned && $p === null) {
                continue;
            }

            $data[] = [
                'type' => 'guide',
                'id' => $guide->id,
                'title' => $guide->title,
                'assigned' => $isAssigned,
                'last_screen' => $p?->last_screen,
                'screen_count' => $guide->screenCount(),
                'completed_at' => $p?->completed_at?->toIso8601String(),
            ];
        }

        foreach ($tests as $test) {
            $isAssigned = in_array($test->id, $assignedTestIds, true);
            $s = $standing->get($test->id);
            $attempts = $historyByTest->get($test->id, collect());
            $lapsed = $s !== null && $s->passed && $s->expires_on !== null && $s->expires_on->toDateString() <= $today;

            // `$standing` is the latest PASSED attempt, so testing it alone
            // dropped anybody who has only ever FAILED an unassigned test —
            // exactly the person a record card is most wanted for. Asking
            // the history instead is what "all" means here: any attempt at
            // all earns a row.
            if (! $isAssigned && $s === null && $attempts->isEmpty()) {
                continue;
            }

            $data[] = [
                'type' => 'test',
                'id' => $test->id,
                'title' => $test->title,
                'assigned' => $isAssigned,
                'score' => $s?->score,
                'passed' => $s?->passed,
                'expires_on' => $s?->expires_on?->toDateString(),
                'due_for_retaking' => $lapsed,
                'attempts' => $attempts->map(fn (TrainingAttempt $a) => [
                    'taken_at' => $a->taken_at?->toIso8601String(),
                    'score' => $a->score,
                    'passed' => $a->passed,
                    'expires_on' => $a->expires_on?->toDateString(),
                ])->values()->all(),
            ];
        }

        return response()->json([
            // Identity is disclosed only alongside actual content — see
            // task-11 review, Critical 1 finding 8. A monitor who reaches
            // this person but for whom every row was filtered out (nothing
            // assigned, nothing attempted, nothing in progress) learns
            // nothing about them from this response, the same as one who
            // never reaches them at all.
            'user' => $data === [] ? null : ['id' => $subject->id, 'name' => $subject->name],
            'data' => $data,
        ]);
    }

    // ── Authorization ────────────────────────────────────────────────

    private function authorizeMonitor(User $user): void
    {
        if (! $user->hasPermission('training.monitor')) {
            Refusal::permission('watch training')->authorize();
        }
    }

    /**
     * Person reach, not content reach — see the class docblock. Built on
     * `ScopeReach::constrainToVisiblePeople`, the one predicate for "may you
     * see this person" (its own docblock), never a fourth copy of the match.
     */
    private function canReachPerson(User $subject, User $actor): bool
    {
        return User::query()
            ->whereKey($subject->getKey())
            ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
            ->exists();
    }

    /**
     * Why `records` refused a person outside reach — a plain sentence, same
     * split `UserPolicy::outOfBounds` uses: somebody assigned nowhere needs
     * to hear that, rather than being told a place they never had.
     */
    private function refusePersonReach(User $actor): Response
    {
        if ($actor->assignments->isEmpty()) {
            return Response::deny(
                'You hold the permission to watch training, but you are not assigned '
                .'anywhere yet, so there is nobody else whose training records are yours to '
                .'see. An administrator can assign you to a part of the business.'
            );
        }

        return Response::deny(
            'You hold the permission to watch training, but this person is not inside the '
            .'part of the business you are assigned to, so their training records are not '
            .'yours to see. Somebody with authority over them can — and an administrator can '
            .'widen your assignment.'
        );
    }

    /**
     * User ids among $ids that $actor has PERSON reach over, via
     * `ScopeReach::constrainToVisiblePeople` — the `WHERE id IN (...)`
     * itself is ONE query for however many ids are passed in, never one per
     * id; `ScopeReach` also spends two more resolving $actor's own reachable
     * companies/franchises the first time it is asked, memoized after (see
     * the class docblock, "Scale", bullets 7-9).
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function reachablePeople(array $ids, User $actor): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Every person id these three structures mention, unioned — the universe
     * [reachablePeople] is asked about, gathered before the per-guide/per-
     * test loop so the reach query runs once for the whole request rather
     * than once per row.
     *
     * @param  array{guide: array<string, array{assigned: array<string, true>, overdue_candidates: array<string, true>}>, test: array<string, array{assigned: array<string, true>, overdue_candidates: array<string, true>}>}  $assignments
     * @param  array<string, array{passed: array<string, true>, started: array<string, true>}>  $guideActivity
     * @param  array<string, array{passed: array<string, true>, retaking: array<string, true>, attempted: array<string, true>}>  $testActivity
     * @return list<string>
     */
    private function allPeopleIds(array $assignments, array $guideActivity, array $testActivity): array
    {
        $ids = [];

        foreach (['guide', 'test'] as $kind) {
            foreach ($assignments[$kind] as $entry) {
                $ids += $entry['assigned'] + $entry['overdue_candidates'];
            }
        }

        foreach ($guideActivity as $entry) {
            $ids += $entry['passed'] + $entry['started'];
        }

        foreach ($testActivity as $entry) {
            $ids += $entry['passed'] + $entry['retaking'] + $entry['attempted'];
        }

        return array_keys($ids);
    }

    // ── Visible content, same engine as the rest of the module ────────

    /** @return Collection<string, TrainingGuide> */
    private function visibleGuides(User $user, ViewScope $scope): Collection
    {
        return InventoryVisibility::constrain(
            TrainingGuide::query()->where('status', TrainingGuide::PUBLISHED),
            $user,
            $scope,
        )->orderBy('title')->get()->keyBy('id');
    }

    /** @return Collection<string, TrainingTest> */
    private function visibleTests(User $user, ViewScope $scope): Collection
    {
        return InventoryVisibility::constrain(
            TrainingTest::query()->where('status', TrainingTest::PUBLISHED),
            $user,
            $scope,
        )->orderBy('title')->get()->keyBy('id');
    }

    /**
     * @return array{0: 'guide'|'test', 1: TrainingGuide|TrainingTest}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    private function findVisibleItem(string $id, User $user, ViewScope $scope): array
    {
        $guide = $this->visibleGuides($user, $scope)->get($id);

        if ($guide !== null) {
            return ['guide', $guide];
        }

        $test = $this->visibleTests($user, $scope)->get($id);

        if ($test !== null) {
            return ['test', $test];
        }

        throw new \Illuminate\Database\Eloquent\ModelNotFoundException("No training item [{$id}].");
    }

    // ── Assignment audience: one query, plus one for role members ─────

    /**
     * Every assignment targeting any of $guideIds/$testIds, expanded to real
     * people — a ROLE assignee is expanded to that role's current ACTIVE
     * members, the same union `LearnController::assignedQuery` computes at
     * read time (decision 1: a role assignment is never expanded into
     * per-user rows in storage, only when read).
     *
     * @param  list<string>  $guideIds
     * @param  list<string>  $testIds
     * @return array{
     *     guide: array<string, array{assigned: array<string, true>, overdue_candidates: array<string, true>, due_on: array<string, string>}>,
     *     test: array<string, array{assigned: array<string, true>, overdue_candidates: array<string, true>, due_on: array<string, string>}>,
     * }
     */
    private function assignmentAudience(array $guideIds, array $testIds): array
    {
        $result = ['guide' => [], 'test' => []];

        if ($guideIds === [] && $testIds === []) {
            return $result;
        }

        $assignments = TrainingAssignment::query()
            ->where(function ($q) use ($guideIds, $testIds) {
                $q->whereIn('guide_id', $guideIds)->orWhereIn('test_id', $testIds);
            })
            ->get(['guide_id', 'test_id', 'assignee_type', 'assignee_id', 'due_on']);

        $roleIds = $assignments->where('assignee_type', TrainingAssignment::ROLE)
            ->pluck('assignee_id')->unique()->values()->all();

        $roleMembers = $roleIds === []
            ? new EloquentCollection
            : User::query()->whereIn('role_id', $roleIds)->where('is_active', true)->get(['id', 'role_id']);

        $membersByRole = $roleMembers->groupBy('role_id')->map(fn ($users) => $users->pluck('id')->all());

        $today = now()->toDateString();

        foreach ($assignments as $a) {
            $kind = $a->guide_id !== null ? 'guide' : 'test';
            $key = $a->guide_id ?? $a->test_id;

            $result[$kind][$key] ??= ['assigned' => [], 'overdue_candidates' => [], 'due_on' => []];

            $people = $a->assignee_type === TrainingAssignment::USER
                ? [$a->assignee_id]
                : ($membersByRole[$a->assignee_id] ?? []);

            $dueOn = $a->due_on?->toDateString();

            foreach ($people as $personId) {
                $result[$kind][$key]['assigned'][$personId] = true;

                if ($dueOn !== null) {
                    // Earliest due date wins when more than one assignment
                    // row reaches the same person (a direct one and a role
                    // one, say) — the more urgent date is the one that
                    // matters to somebody watching for overdue people.
                    $existing = $result[$kind][$key]['due_on'][$personId] ?? null;

                    if ($existing === null || $dueOn < $existing) {
                        $result[$kind][$key]['due_on'][$personId] = $dueOn;
                    }
                }

                // Strictly `<`, not `<=` — a due date of TODAY is not overdue
                // until tomorrow. `retaking` below uses `<=` deliberately: it
                // mirrors `LapsedPasses`/`ExpireTrainingPasses`'s own
                // definition of "expired", which is inclusive of today (task-11
                // review, minor). Two different facts, kept asymmetric on
                // purpose rather than forced to match.
                if ($dueOn !== null && $dueOn < $today) {
                    $result[$kind][$key]['overdue_candidates'][$personId] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Which of $guideIds a subject is assigned — directly, or via the one
     * role they hold. Used only by [records], for one person at a time.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function assignedTargetIds(User $subject, array $ids, string $column): array
    {
        if ($ids === []) {
            return [];
        }

        return TrainingAssignment::query()
            ->whereIn($column, $ids)
            ->where(function ($q) use ($subject) {
                $q->where(fn ($q2) => $q2->where('assignee_type', TrainingAssignment::USER)->where('assignee_id', $subject->getKey()));

                if ($subject->role_id !== null) {
                    $q->orWhere(fn ($q2) => $q2->where('assignee_type', TrainingAssignment::ROLE)->where('assignee_id', $subject->role_id));
                }
            })
            ->pluck($column)
            ->unique()
            ->values()
            ->all();
    }

    // ── Guide activity: one query ──────────────────────────────────────

    /**
     * @param  list<string>  $guideIds
     * @return array<string, array{passed: array<string, true>, started: array<string, true>}>
     */
    private function guideActivity(array $guideIds): array
    {
        $result = [];

        if ($guideIds === []) {
            return $result;
        }

        TrainingProgress::query()
            ->whereIn('guide_id', $guideIds)
            ->get(['guide_id', 'user_id', 'completed_at'])
            ->each(function (TrainingProgress $p) use (&$result) {
                $result[$p->guide_id] ??= ['passed' => [], 'started' => []];

                if ($p->completed_at !== null) {
                    $result[$p->guide_id]['passed'][$p->user_id] = true;
                } else {
                    $result[$p->guide_id]['started'][$p->user_id] = true;
                }
            });

        return $result;
    }

    /** @return array<string, array{last_screen: int, screen_count: int, completed_at: ?string}> */
    private function guideDetailByUser(string $guideId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return TrainingProgress::query()
            ->where('guide_id', $guideId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'last_screen', 'completed_at'])
            ->mapWithKeys(fn (TrainingProgress $p) => [$p->user_id => [
                'last_screen' => $p->last_screen,
                'completed_at' => $p->completed_at?->toIso8601String(),
            ]])
            ->all();
    }

    // ── Test activity: current standing, plus "ever attempted" ─────────

    /**
     * @param  list<string>  $testIds
     * @return array<string, array{passed: array<string, true>, retaking: array<string, true>, attempted: array<string, true>}>
     */
    private function testActivity(array $testIds): array
    {
        $result = [];

        if ($testIds === []) {
            return $result;
        }

        $today = now()->toDateString();

        LapsedPasses::currentStandingQuery($testIds)
            ->get(['training_attempts.test_id', 'training_attempts.user_id', 'training_attempts.expires_on'])
            ->each(function (TrainingAttempt $a) use (&$result, $today) {
                $result[$a->test_id] ??= ['passed' => [], 'retaking' => [], 'attempted' => []];

                $lapsed = $a->expires_on !== null && $a->expires_on->toDateString() <= $today;

                $result[$a->test_id][$lapsed ? 'retaking' : 'passed'][$a->user_id] = true;
            });

        TrainingAttempt::query()
            ->whereIn('test_id', $testIds)
            ->select(['test_id', 'user_id'])
            ->distinct()
            ->get()
            ->each(function (TrainingAttempt $a) use (&$result) {
                $result[$a->test_id] ??= ['passed' => [], 'retaking' => [], 'attempted' => []];
                $result[$a->test_id]['attempted'][$a->user_id] = true;
            });

        return $result;
    }

    /** @return array<string, array{score: ?int, passed: ?bool, expires_on: ?string}> */
    private function testDetailByUser(string $testId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Every attempt, latest first — the whole history, not just the
        // current standing (owner, 2026-09-09, asked whether this pane shows
        // a person's whole history or only where they stand now; his answer
        // was "all").
        //
        // The top-level `score`/`passed`/`expires_on` still describe the
        // LATEST attempt and are unchanged, because the row's own `state`
        // is built from current standing and the two must not disagree in
        // the same row. `attempts` is added ALONGSIDE them rather than
        // replacing them: a reader wanting "where are they now" keeps the
        // answer it already had, and one wanting "how did they get here"
        // now has it too.
        //
        // `groupBy` preserves each group's original sequence (Laravel's own
        // contract), so every list stays taken_at/id descending — the same
        // property `LapsedPasses::attemptsCountingTowardCapBatch` relies on.
        return TrainingAttempt::query()
            ->where('test_id', $testId)
            ->whereIn('user_id', $userIds)
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->get(['user_id', 'score', 'passed', 'taken_at', 'expires_on'])
            ->groupBy('user_id')
            ->map(function (Collection $attempts) {
                $latest = $attempts->first();

                return [
                    'score' => $latest->score,
                    'passed' => $latest->passed,
                    'expires_on' => $latest->expires_on?->toDateString(),
                    'attempts' => $attempts->map(fn (TrainingAttempt $a) => [
                        'taken_at' => $a->taken_at?->toIso8601String(),
                        'score' => $a->score,
                        'passed' => $a->passed,
                        'expires_on' => $a->expires_on?->toDateString(),
                    ])->values()->all(),
                ];
            })
            ->all();
    }

    // ── Turning sets into states and rows ───────────────────────────────

    /**
     * One priority order, applied to every guide and every test row alike:
     * PASSED and RETAKING first (a currently-valid pass, or a lapsed one, is
     * never also "overdue" or "in progress" — those describe somebody who
     * has not yet reached either), then OVERDUE (an assigned person past
     * their due date who has done neither), then IN_PROGRESS (started, not
     * yet due or with no due date at all).
     *
     * None of the four is a subset of `assigned`. Training has a LIBRARY as
     * well as an assignment system, so somebody can pass — or fail, or start
     * — a test nobody assigned them; `passed`/`retaking`/`started` are drawn
     * from actual activity, not from the assignment rows. A response with
     * `people_assigned: 0` beside `people_due_for_retaking: 1` is not a bug:
     * nobody is assigned, and one person's library pass has lapsed all the
     * same. (An earlier version of this comment claimed the four states
     * were confined to assigned people; the code was already right, the
     * claim was not — task-11 review, Important 5. See
     * `TrainingDashboardTest::test_a_library_pass_can_lapse_with_nobody_assigned`.)
     * A person in none of the four sets simply has no state this response
     * names — assigned-but-not-yet-due-or-started, or no activity and no
     * assignment at all; the response does not distinguish the two.
     *
     * @param  array<string, true>  $assigned
     * @param  array<string, true>  $overdueCandidates
     * @param  array<string, true>  $passed
     * @param  array<string, true>  $retaking
     * @param  array<string, true>  $started
     * @return array<string, array<string, true>>
     */
    private function statesFor(array $assigned, array $overdueCandidates, array $passed, array $retaking, array $started): array
    {
        $overdue = array_diff_key($overdueCandidates, $passed, $retaking);
        $inProgress = array_diff_key($started, $passed, $retaking, $overdue);

        return [
            'assigned' => $assigned,
            self::PASSED => $passed,
            self::RETAKING => $retaking,
            self::OVERDUE => $overdue,
            self::IN_PROGRESS => $inProgress,
        ];
    }

    /** @param  array<string, array<string, true>>  $states */
    private function stateOf(string $userId, array $states): string
    {
        foreach ([self::RETAKING, self::PASSED, self::OVERDUE, self::IN_PROGRESS] as $state) {
            if (isset($states[$state][$userId])) {
                return $state;
            }
        }

        return 'not_started';
    }

    /** @param  array<string, array<string, true>>  $states */
    /**
     * `kind` is `'guide'` or `'test'` — added for task "close the assign
     * gap" (audit-fixes-report.md): the Dashboard pane's "Assign to more
     * people" control (`client/lib/screens/training/dashboard_pane.dart`)
     * posts to `/training/assignments`, which needs EXACTLY ONE of
     * `guide_id`/`test_id` (`AssignmentsController::store`), and this row
     * — carried through unchanged into `DashboardPane.row` — was the only
     * place that could ever say which one without the client guessing. Not
     * added to a per-person row in [people]: that stays exactly as it was,
     * still with no `type` field, because every row's audience there is
     * unambiguously the ONE item [findVisibleItem] already resolved a kind
     * for, one level up.
     */
    private function row(string $id, string $title, array $states, string $kind): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'kind' => $kind,
            'assigned' => count($states['assigned']),
            self::PASSED => count($states[self::PASSED]),
            self::IN_PROGRESS => count($states[self::IN_PROGRESS]),
            self::OVERDUE => count($states[self::OVERDUE]),
            self::RETAKING => count($states[self::RETAKING]),
        ];
    }
}
