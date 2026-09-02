<?php

namespace App\Support\Training;

use App\Models\AutomationEvent;
use App\Models\Role;
use App\Models\TrainingAssignment;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Automations\EventEmitter;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writing one training assignment (training task-9 brief).
 *
 * The ONE write path — `AssignmentsController::store` and `ActionRunner`'s
 * `training.assign` arm both call [create] and nothing else, so a rule
 * reaches no further, and needs no more permission, than the person who
 * saved it could reach and hold by hand clicking the same button.
 * Reach-bounding lives in `AssignmentReach`, which this class asks and never
 * re-derives.
 *
 * ── The permission gate lives HERE, not only in the controller ─────
 *
 * Review (task-9 round 2) found that `training.assign` was checked ONLY in
 * `AssignmentsController::store` — never in `ActionRunner::assignTraining`
 * or here. `assertTaskActionUsable` (`AutomationsController`) only runs its
 * per-type permission check for `TASK_ACTIONS`, and `training.assign` is not
 * one, so a rule using it saved and ran with no permission check at all: a
 * person holding `training.view` and the automation permissions, but NOT
 * `training.assign`, was refused `403` on a direct `POST`
 * `/training/assignments` and then simply wrote a rule that did the
 * identical thing instead — `201`, `succeeded`. That is the exact escalation
 * `training.assign` exists to prevent (this module invented it precisely to
 * separate "may learn" from "may impose training" — TrainingGuidePolicy's
 * own docblock), and the "ONE write path" claim above was false about
 * PERMISSION even though it was true about reach.
 *
 * Fixed by checking it here, first, before anything else in [create] —
 * matching the order `HrInvitationsController::store`'s own docblock lists
 * its refusals in ("you may not invite at all" comes before any §4 check) —
 * so `AssignmentsController::store`'s own separate check became a second
 * home for the identical fact and was removed rather than kept alongside
 * this one; there is now exactly one gate, asked once, on the one path both
 * a person and a rule take.
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
 * ── The target must be real: published, and — for a person — visible ──
 *
 * Review also found `findOrFail` alone let a DRAFT guide/test be assigned,
 * or one outside the learner's own place, producing a `201` that could never
 * come true: `/learn` never renders a row whose target is not published and
 * place-visible (`LearnController::readableGuides`/`readableTests`,
 * `presentAssignment`), and this task adds no list endpoint a human could
 * use to notice the dud sitting there. [exactlyOneTarget] now requires
 * `status === PUBLISHED` the same way `LearnController::showTest` does — a
 * draft or a missing id come back identically (`ModelNotFoundException`,
 * the same "which of the two it was is not this response's to say" choice
 * that controller already makes. [assertLearnerCanSee] additionally checks,
 * for a USER assignee only, that the exact `InventoryVisibility`-constrained
 * query `readableGuides`/`readableTests` build from the LEARNER's own
 * `ViewScope::forUser` would include this row — the identical computation
 * that decides whether `/learn` will ever render it, so "will this show up"
 * is answered by the same code that answers it for real, not a second guess
 * at the same question. A ROLE assignee has no single learner to check this
 * against, and — the same reasoning decision 1 already gives for reach not
 * being re-walked per member — a per-member snapshot would be exactly the
 * kind of promise membership can outgrow; visibility for a role assignment
 * is left to the union at Learn read time, same as everything else about a
 * role target.
 *
 * ── Decision: a repeat assignment updates in place, never a second row ────
 *
 * A double-click, a client retry, or a rule that matches the same event
 * twice can all call [create] a second time with the identical guide/test
 * and the identical assignee. Nothing about the schema stops it — there is
 * no unique index on (assignee_type, assignee_id, guide_id, test_id) — and
 * `LearnController::assignedQuery`/`presentAssignment` render one card per
 * ROW, so two identical rows would show the same guide twice on one
 * learner's list. That is a worse failure than the request itself doing
 * nothing new the second time.
 *
 * So a repeat is answered by handing back the assignment that already
 * exists — updating its `due_on` when the repeat NAMES one, rather than
 * silently keeping the first request's date and saying nothing (review,
 * round 2: a `201` with the OLD date on a request that named a NEW one told
 * the caller nothing was wrong). `AssignmentsController::store` reads
 * Eloquent's own `wasRecentlyCreated` to answer `200` for the
 * updated-in-place case and `201` only for a genuine insert — no second flag
 * to carry, since that property already means exactly this.
 *
 * ── Absent is not the same as null (review, round 3) ────────────────
 *
 * Round 2's fix wrote `$input['due_on'] ?? null` for the repeat branch,
 * which reads "the field was not sent" and "the field was sent as null" as
 * the identical case — so a repeat with NO `due_on` at all erased a stored
 * date rather than leaving it alone. Reachable, and worse than cosmetic,
 * through `ActionRunner::assignTraining`: that arm always passed a
 * `due_on` KEY (`$action['due_on'] ?? null`), present even when the rule
 * author never configured one, so a rule with no date firing a second time
 * silently wiped a date a person had set by hand, and the response — `200`
 * — gave no sign anything had changed.
 *
 * Fixed by checking presence, not merely value: `array_key_exists('due_on',
 * $input)` (`AssignmentsController`'s `sometimes` rule already means the
 * validated array simply omits the key when the field was not sent, and
 * `ActionRunner::assignTraining` now only includes the key when the rule's
 * OWN action config set one — see its own comment). Three states, all
 * tested:
 *
 *   - KEY ABSENT      → the stored `due_on` is left exactly as it was.
 *   - KEY PRESENT, null → cleared deliberately (a caller that already had a
 *     date and now sends an explicit `null` is saying "no due date any
 *     more", not "I forgot to mention one" — the same present/absent
 *     distinction a `PATCH` makes, applied to a `POST` that happens to be
 *     idempotent for an existing target).
 *   - KEY PRESENT, a date → updated, as round 2 already did.
 *
 * The check has to be race-free the same way `LearnController::submit`'s
 * retake cap is: two requests for the SAME guide/test racing each other must
 * not both see "no existing row" and both insert. What `submit` locks for
 * that is the TEST row itself — the one row every submission against it
 * already touches — and [create] does the identical thing here with the
 * GUIDE or TEST being assigned, which every assignment of it already
 * touches too: `lockForUpdate()` on that one row serializes every write
 * racing to assign it, and the existence check that follows is then
 * race-free rather than a read-then-write gap
 * (StaffAccountsController::update's own docblock on why that gap is a
 * race and not a uniqueness rule).
 */
final class AssignmentWriter
{
    public function __construct(private readonly EventEmitter $events) {}

    /**
     * @param  array{guide_id?: ?string, test_id?: ?string, assignee_type?: ?string, assignee_id?: ?string, due_on?: ?string}  $input
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  the assigner
     *                               lacks `training.assign`, or (§4) their
     *                               reach does not cover the assignee
     * @throws ValidationException  neither/both of guide_id and test_id, no
     *                               valid assignee named, or the target is
     *                               not something the assignee could ever be
     *                               shown
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  the
     *                               guide, test, person or role named does
     *                               not exist in this tenant, or is a draft
     */
    public function create(User $assigner, array $input, ?AutomationEvent $causedBy = null): TrainingAssignment
    {
        if (! $assigner->hasPermission('training.assign')) {
            Refusal::permission('assign training')->authorize();
        }

        [$guide, $test] = $this->exactlyOneTarget($input['guide_id'] ?? null, $input['test_id'] ?? null);

        $assigneeType = $input['assignee_type'] ?? null;
        $assigneeId = $input['assignee_id'] ?? null;

        if (! in_array($assigneeType, [TrainingAssignment::USER, TrainingAssignment::ROLE], true) || $assigneeId === null) {
            throw ValidationException::withMessages([
                'assignee_type' => 'Name a person or a role to give this training to.',
            ]);
        }

        if ($assigneeType === TrainingAssignment::USER) {
            $assignee = $this->authorizeUser($assigner, $assigneeId);
            $this->assertLearnerCanSee($guide, $test, $assignee);
        } else {
            $this->authorizeRole($assigner, $assigneeId);
        }

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
                // Absent means "leave it alone", not "clear it" — see this
                // class's own docblock, "Absent is not the same as null".
                if (array_key_exists('due_on', $input)) {
                    $incomingDueOn = $input['due_on'];

                    if (optional($existing->due_on)->toDateString() !== $incomingDueOn) {
                        $existing->due_on = $incomingDueOn;
                        $existing->save();
                    }
                }

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

        // PUBLISHED only — the same "a draft answers the same as one that
        // does not exist" choice `LearnController::showTest` makes, and for
        // the identical reason: a draft is never something a learner could
        // ever be shown, so a row assigning one is a dud from the moment it
        // is written. See this class's own docblock.
        return $guideId !== null
            ? [TrainingGuide::query()->where('status', TrainingGuide::PUBLISHED)->findOrFail($guideId), null]
            : [null, TrainingTest::query()->where('status', TrainingTest::PUBLISHED)->findOrFail($testId)];
    }

    /**
     * For a USER assignee only — see this class's own docblock on why a role
     * target is not checked this way.
     */
    private function assertLearnerCanSee(?TrainingGuide $guide, ?TrainingTest $test, User $learner): void
    {
        $scope = ViewScope::forUser($learner);

        $visible = $guide !== null
            ? InventoryVisibility::constrain(TrainingGuide::query(), $learner, $scope)->whereKey($guide->getKey())->exists()
            : InventoryVisibility::constrain(TrainingTest::query(), $learner, $scope)->whereKey($test->getKey())->exists();

        if (! $visible) {
            throw ValidationException::withMessages([
                $guide !== null ? 'guide_id' : 'test_id' => 'Give this assignment something the learner can '
                    .'actually see — this one is outside where they are assigned.',
            ]);
        }
    }

    private function authorizeUser(User $assigner, string $assigneeId): User
    {
        $assignee = User::findOrFail($assigneeId);

        if (! AssignmentReach::mayAssignTo($assigner, $assignee)) {
            AssignmentReach::explain($assigner, $assignee)->authorize();
        }

        return $assignee;
    }

    private function authorizeRole(User $assigner, string $roleId): void
    {
        $role = Role::findOrFail($roleId);

        if (! AssignmentReach::mayAssignToRole($assigner, $role)) {
            AssignmentReach::explainRole($assigner, $role)->authorize();
        }
    }
}
