<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\TrainingAssignment;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Training\LearnerView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 */
class LearnController extends Controller
{
    /** GET /api/v1/training/learn */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLearner($user);

        $scope = ViewScope::forUser($user);

        return response()->json([
            'assigned' => $this->assignedQuery($user)->get()
                ->map(fn (TrainingAssignment $assignment) => $this->presentAssignment($assignment))
                ->values(),
            'library' => $this->library($user, $scope),
        ]);
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

        return response()->json(['data' => $this->presentTest($row)]);
    }

    private function authorizeLearner(User $user): void
    {
        if (! $user->hasPermission('training.view')) {
            Refusal::permission('see your training')->authorize();
        }
    }

    /**
     * Guides and tests a learner may reach, in their own view scope,
     * published only (decision 3) — the same InventoryVisibility engine the
     * authoring lists use (GuidesController::index, TestsController::index),
     * pointed at a learner's view instead of a manage-gated one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function library(User $user, ViewScope $scope): array
    {
        $guides = InventoryVisibility::constrain(
            TrainingGuide::query()->where('status', TrainingGuide::PUBLISHED),
            $user,
            $scope,
        )->orderBy('title')->get()->map(fn (TrainingGuide $g) => $this->presentGuide($g));

        $tests = InventoryVisibility::constrain(
            TrainingTest::query()->where('status', TrainingTest::PUBLISHED),
            $user,
            $scope,
        )->orderBy('title')->get()->map(fn (TrainingTest $t) => $this->presentTest($t));

        return $guides->concat($tests)->values()->all();
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
     * Published only, same as the library (decision 3): an assignment whose
     * target has gone back to draft since it was assigned is the authoring
     * surface's business, not something dangled in front of a learner who
     * cannot even see drafts elsewhere.
     */
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
            })
            ->where(function (Builder $published): void {
                $published
                    ->whereHas('guide', fn (Builder $g) => $g->where('status', TrainingGuide::PUBLISHED))
                    ->orWhereHas('test', fn (Builder $t) => $t->where('status', TrainingTest::PUBLISHED));
            })
            ->with(['guide', 'test']);
    }

    /** @return array<string, mixed> */
    private function presentAssignment(TrainingAssignment $assignment): array
    {
        // `test` before `guide`: the two are mutually exclusive in every row
        // Task 9 writes, but the column pair does not enforce that itself,
        // and a defensible default is needed for whichever one is set.
        $content = $assignment->test !== null
            ? $this->presentTest($assignment->test)
            : $this->presentGuide($assignment->guide);

        return [
            'assignment_id' => $assignment->id,
            'due_on' => $assignment->due_on?->toDateString(),
            'assignee_type' => $assignment->assignee_type,
            ...$content,
        ];
    }

    /** @return array<string, mixed> */
    private function presentGuide(TrainingGuide $guide): array
    {
        return [
            'type' => 'guide',
            'id' => $guide->id,
            'title' => $guide->title,
            'summary' => $guide->summary,
            'company_id' => $guide->company_id,
            'franchise_id' => $guide->franchise_id,
            'screen_count' => $guide->screenCount(),
            'screens' => $guide->screens,
        ];
    }

    /**
     * Every field a raw {@see TestsController::present} would show, except
     * `questions` — which never leaves this class as `$test->questions`.
     * It is always the return of {@see LearnerView::stripAnswerKey}, here and
     * everywhere else in this controller that mentions a test.
     *
     * @return array<string, mixed>
     */
    private function presentTest(TrainingTest $test): array
    {
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
            'valid_for_days' => $test->valid_for_days,
            'question_count' => $test->questionCount(),
            'questions' => LearnerView::stripAnswerKey($test->questions ?? []),
        ];
    }
}
