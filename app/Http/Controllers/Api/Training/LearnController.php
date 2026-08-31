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
use Illuminate\Support\Collection;

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
 */
class LearnController extends Controller
{
    /** GET /api/v1/training/learn */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLearner($user);

        $scope = ViewScope::forUser($user);
        $guides = $this->readableGuides($user, $scope);
        $tests = $this->readableTests($user, $scope);

        return response()->json([
            'assigned' => $this->assigned($user, $guides, $tests),
            'library' => $this->library($guides, $tests),
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
     * @return array<int, array<string, mixed>>
     */
    private function library(Collection $guides, Collection $tests): array
    {
        return $guides->map(fn (TrainingGuide $g) => $this->presentGuide($g))
            ->concat($tests->map(fn (TrainingTest $t) => $this->presentTest($t)))
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
     * @return array<int, array<string, mixed>>
     */
    private function assigned(User $user, Collection $guides, Collection $tests): array
    {
        return $this->assignedQuery($user)
            ->get()
            ->map(fn (TrainingAssignment $a) => $this->presentAssignment($a, $guides, $tests))
            ->filter()
            ->values()
            ->all();
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
     * @return array<string, mixed>|null
     */
    private function presentAssignment(TrainingAssignment $assignment, Collection $guides, Collection $tests): ?array
    {
        $content = match (true) {
            $assignment->test_id !== null && $tests->has($assignment->test_id)
                => $this->presentTest($tests->get($assignment->test_id)),
            $assignment->guide_id !== null && $guides->has($assignment->guide_id)
                => $this->presentGuide($guides->get($assignment->guide_id)),
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
