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
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Training\LapsedPasses;
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
 * ── Scoped the same way as every other read in this module ─────────
 *
 * Every row this controller can ever show comes from
 * `InventoryVisibility::constrain`, applied to the GUIDE or TEST itself —
 * the same engine `GuidesController::index` and
 * `LearnController::readableGuides`/`readableTests` already use. A guide
 * outside the monitor's own reach never appears as a row at all, so its
 * assigned/passed/overdue/retaking counts never appear either — two people
 * at different franchises watching "the same guide" (an organization-wide
 * one, say) see the SAME counts for it, and two people where one of them
 * cannot see a franchise-tagged guide at all see a different LIST, which is
 * the correct shape for "different counts" (§4).
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
 * ── Scale: six queries, however many guides/tests/people exist ──────
 *
 * [index] issues exactly six queries regardless of how many guides, tests,
 * or people the organization has — measured against 50 guides/tests and 500
 * people (see the task-11 report for the literal counts):
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
 *
 * That is seven, not six, when a role is named — the count grows with the
 * number of DISTINCT queries this method issues, never with the number of
 * rows or people it returns; every one of the seven selects a set, and
 * every subsequent step is a PHP-side group-by over an already-fetched
 * collection, not a loop that queries again per guide or per test.
 */
class DashboardController extends Controller
{
    private const PASSED = 'passed';

    private const RETAKING = 'retaking';

    private const OVERDUE = 'overdue';

    private const IN_PROGRESS = 'in_progress';

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

        $rows = [];
        $peopleAssigned = [];
        $peopleOverdue = [];
        $peopleRetaking = [];

        foreach ($guides as $guide) {
            $states = $this->statesFor(
                assigned: $assignments['guide'][$guide->id]['assigned'] ?? [],
                overdueCandidates: $assignments['guide'][$guide->id]['overdue_candidates'] ?? [],
                passed: $guideActivity[$guide->id]['passed'] ?? [],
                retaking: [],
                started: $guideActivity[$guide->id]['started'] ?? [],
            );

            $rows[] = $this->row($guide->id, $guide->title, $states);
            $peopleAssigned += $states['assigned'];
            $peopleOverdue += $states[self::OVERDUE];
            $peopleRetaking += $states[self::RETAKING];
        }

        foreach ($tests as $test) {
            $states = $this->statesFor(
                assigned: $assignments['test'][$test->id]['assigned'] ?? [],
                overdueCandidates: $assignments['test'][$test->id]['overdue_candidates'] ?? [],
                passed: $testActivity[$test->id]['passed'] ?? [],
                retaking: $testActivity[$test->id]['retaking'] ?? [],
                started: $testActivity[$test->id]['attempted'] ?? [],
            );

            $rows[] = $this->row($test->id, $test->title, $states);
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
            $lapsed = $s !== null && $s->passed && $s->expires_on !== null && $s->expires_on->toDateString() <= $today;

            if (! $isAssigned && $s === null) {
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
            ];
        }

        return response()->json([
            'user' => ['id' => $subject->id, 'name' => $subject->name],
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

        // Every attempt, latest first, so the first one kept per user in
        // the map below is their most recent — the same "what does this
        // person see right now" question the row states already answer,
        // just carrying the score and pass flag alongside it.
        return TrainingAttempt::query()
            ->where('test_id', $testId)
            ->whereIn('user_id', $userIds)
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->get(['user_id', 'score', 'passed', 'expires_on'])
            ->unique('user_id')
            ->mapWithKeys(fn (TrainingAttempt $a) => [$a->user_id => [
                'score' => $a->score,
                'passed' => $a->passed,
                'expires_on' => $a->expires_on?->toDateString(),
            ]])
            ->all();
    }

    // ── Turning sets into states and rows ───────────────────────────────

    /**
     * One priority order, applied to every guide and every test row alike:
     * PASSED and RETAKING first (a currently-valid pass, or a lapsed one, is
     * never also "overdue" or "in progress" — those describe somebody who
     * has not yet reached either), then OVERDUE (an assigned person past
     * their due date who has done neither), then IN_PROGRESS (started, not
     * yet due or with no due date at all). A person outside all four sets
     * is assigned but has not started and is not yet due — not a state this
     * response names, the same way `assigned` itself is a headcount rather
     * than a bucket.
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
    private function row(string $id, string $title, array $states): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'assigned' => count($states['assigned']),
            self::PASSED => count($states[self::PASSED]),
            self::IN_PROGRESS => count($states[self::IN_PROGRESS]),
            self::OVERDUE => count($states[self::OVERDUE]),
            self::RETAKING => count($states[self::RETAKING]),
        ];
    }
}
