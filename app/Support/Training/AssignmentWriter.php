<?php

namespace App\Support\Training;

use App\Models\AutomationEvent;
use App\Models\Role;
use App\Models\TrainingAssignment;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writing one training assignment (training task-9 brief).
 *
 * The ONE write path — `AssignmentsController::store` and `ActionRunner`'s
 * `training.assign` arm both call [create] and nothing else, so a rule
 * reaches no further than the person who saved it could reach by hand
 * clicking the same button. Reach-bounding lives in `AssignmentReach`, which
 * this class asks and never re-derives.
 *
 * ── Decision 2: exactly one of guide_id/test_id, refused at this boundary ──
 *
 * Both columns are plain nullable FKs with no CHECK constraint — the database
 * would happily store neither or both. `LearnController::presentAssignment`
 * already had to cope with that ambiguity for the (nonexistent, until this
 * task) rows it might one day read. Refusing it HERE, in the one place a row
 * is ever created, means every row this class ever writes carries exactly
 * one target.
 *
 * ── Decision: a repeat assignment is a no-op, not a second row ─────────────
 *
 * A double-click, a client retry, or a rule that matches the same event
 * twice can all call [create] a second time with the identical guide/test
 * and the identical assignee. Nothing about the schema stops it — there is
 * no unique index on (assignee_type, assignee_id, guide_id, test_id) — and
 * `LearnController::assignedQuery`/`presentAssignment` render one card per
 * ROW, so two identical rows would show the same guide twice on one
 * learner's list. That is a worse failure than the request itself doing
 * nothing the second time.
 *
 * So a repeat is answered by handing back the assignment that already
 * exists, rather than writing another. The check has to be race-free the
 * same way `LearnController::submit`'s retake cap is: two requests for the
 * SAME guide/test racing each other must not both see "no existing row" and
 * both insert. What `submit` locks for that is the TEST row itself — the
 * one row every submission against it already touches — and [create] does
 * the identical thing here with the GUIDE or TEST being assigned, which
 * every assignment of it already touches too: `lockForUpdate()` on that one
 * row serializes every write racing to assign it, and the existence check
 * that follows is then race-free rather than a read-then-write gap
 * (StaffAccountsController::update's own docblock on why that gap is a
 * race and not a uniqueness rule).
 *
 * Deliberately NOT a refusal: a person who clicks twice, or a rule that
 * matches twice, gets back the SAME assignment either way — no error to
 * show, and no second `training.assigned` event for one instruction. What
 * this does not cover is a genuine RE-assignment with a different due date;
 * there is no update endpoint (this task's own scope), so changing a due
 * date is not a shape this class has to answer for yet.
 */
final class AssignmentWriter
{
    public function __construct(private readonly EventEmitter $events) {}

    /**
     * @param  array{guide_id?: ?string, test_id?: ?string, assignee_type?: ?string, assignee_id?: ?string, due_on?: ?string}  $input
     *
     * @throws ValidationException  neither/both of guide_id and test_id, or
     *                               no valid assignee named
     * @throws \Illuminate\Auth\Access\AuthorizationException  the assigner's
     *                               reach does not cover the assignee (§4)
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  the
     *                               guide, test, person or role named does
     *                               not exist in this tenant
     */
    public function create(User $assigner, array $input, ?AutomationEvent $causedBy = null): TrainingAssignment
    {
        [$guide, $test] = $this->exactlyOneTarget($input['guide_id'] ?? null, $input['test_id'] ?? null);

        $assigneeType = $input['assignee_type'] ?? null;
        $assigneeId = $input['assignee_id'] ?? null;

        if (! in_array($assigneeType, [TrainingAssignment::USER, TrainingAssignment::ROLE], true) || $assigneeId === null) {
            throw ValidationException::withMessages([
                'assignee_type' => 'Name a person or a role to give this training to.',
            ]);
        }

        $assigneeType === TrainingAssignment::USER
            ? $this->authorizeUser($assigner, $assigneeId)
            : $this->authorizeRole($assigner, $assigneeId);

        return DB::transaction(function () use ($assigner, $guide, $test, $assigneeType, $assigneeId, $input, $causedBy): TrainingAssignment {
            // Lock the guide or test being assigned — the "parent row"
            // LearnController::submit already locks for the identical reason
            // (its own docblock). Every write assigning THIS guide/test now
            // serializes through this one row, so the existence check right
            // after it is race-free rather than read-then-write.
            $guide !== null
                ? TrainingGuide::query()->whereKey($guide->getKey())->lockForUpdate()->firstOrFail()
                : TrainingTest::query()->whereKey($test->getKey())->lockForUpdate()->firstOrFail();

            $existing = TrainingAssignment::query()
                ->where('assignee_type', $assigneeType)
                ->where('assignee_id', $assigneeId)
                ->where('guide_id', $guide?->getKey())
                ->where('test_id', $test?->getKey())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $assignment = TrainingAssignment::create([
                // Filed at the CONTENT's own place — the same place a guide
                // or test itself carries (TrainingGuidePolicy/TrainingTestPolicy),
                // and a different question from automationScope()'s, which
                // answers where the LEARNER stands. See TrainingAssignment's
                // own docblock.
                'company_id' => $guide?->company_id ?? $test?->company_id,
                'franchise_id' => $guide?->franchise_id ?? $test?->franchise_id,
                'guide_id' => $guide?->getKey(),
                'test_id' => $test?->getKey(),
                'assignee_type' => $assigneeType,
                'assignee_id' => $assigneeId,
                'due_on' => $input['due_on'] ?? null,
                'assigned_by_user_id' => $assigner->getKey(),
            ]);

            // `training.assigned`'s published description promises exactly
            // four things: what was given, who or which role it went to, who
            // gave it, and the date it is due if there is one. Nothing else —
            // see this module's own docblock in ModuleServiceProvider on why
            // an outbox payload is an exfiltration surface (`webhook` ships
            // it verbatim to a URL the tenant chose), and task 8's own retreat
            // from shipping more than a trigger's words promised.
            $this->events->emit('training.assigned', $assignment, [
                'after' => [
                    'guide_id' => $assignment->guide_id,
                    'test_id' => $assignment->test_id,
                    'assignee_type' => $assignment->assignee_type,
                    'assignee_id' => $assignment->assignee_id,
                    'assigned_by_user_id' => $assignment->assigned_by_user_id,
                    'due_on' => $assignment->due_on?->toDateString(),
                ],
            ], actor: $assigner, causedBy: $causedBy);

            return $assignment;
        });
    }

    /** @return array{0: ?TrainingGuide, 1: ?TrainingTest} */
    private function exactlyOneTarget(?string $guideId, ?string $testId): array
    {
        if (($guideId === null) === ($testId === null)) {
            throw ValidationException::withMessages([
                'guide_id' => $guideId === null
                    ? 'Give this assignment a guide or a test — neither was sent.'
                    : 'Give this assignment a guide or a test, never both at once.',
            ]);
        }

        return $guideId !== null
            ? [TrainingGuide::findOrFail($guideId), null]
            : [null, TrainingTest::findOrFail($testId)];
    }

    private function authorizeUser(User $assigner, string $assigneeId): void
    {
        $assignee = User::findOrFail($assigneeId);

        if (! AssignmentReach::mayAssignTo($assigner, $assignee)) {
            AssignmentReach::explain($assigner, $assignee)->authorize();
        }
    }

    private function authorizeRole(User $assigner, string $roleId): void
    {
        $role = Role::findOrFail($roleId);

        if (! AssignmentReach::mayAssignToRole($assigner, $role)) {
            AssignmentReach::explainRole($assigner, $role)->authorize();
        }
    }
}
