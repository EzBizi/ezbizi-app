<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\TrainingGuide;
use App\Models\TrainingProgress;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Automations\EventEmitter;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * How far a learner has read into a guide (task-7 brief).
 *
 * The LEARNER surface, gated on `training.view` like LearnController — never
 * `training.manage`. Recording your own reading position is not authoring.
 *
 * ── The same two filters as the read side ─────────────────────────
 *
 * A learner must not be able to record progress against a guide they cannot
 * see: outside their place scope (§4), or still a draft. So [seen] looks the
 * guide up through the identical InventoryVisibility-constrained,
 * published-only query LearnController::readableGuides uses, and — for the
 * same reason [showTest] gives — never through implicit route-model binding.
 * Binding by id alone resolves existence before scope and publication get a
 * say, so a sibling franchise's guide or a draft comes back 404 exactly like
 * one that does not exist, with no signal telling the three apart.
 *
 * ── `last_screen` is a high-water mark ─────────────────────────────
 *
 * Of COMPLETED topics: the reader calls this from "Complete & Continue", not
 * on opening a topic, and a topic past `last_screen + 1` is locked.
 *
 * Never a cursor. Re-reading an earlier screen of a guide already finished is
 * ordinary and must not take the completion away, so the stored value only
 * ever moves forward (`max()`), and `completed_at`, once set, is never
 * cleared or overwritten.
 *
 * ── The event, and why it is an INSERT and not a dispatch ──────────
 *
 * `EventEmitter::emit` is the outbox write itself — `AutomationEvent::create`
 * — not a queue dispatch (see its docblock and StartUp.md §10). Called here
 * inside the same `DB::transaction` as the progress row, so the two commit
 * together or neither does, and a rule that later fails on this event cannot
 * fail the request that raised it. `ezbizi:dispatch-automation-events` is the
 * separate, scheduled reader that turns the row into a real delivery.
 *
 * Raised at most once per completion: `completed_at === null` is the guard —
 * but a plain read-then-write of that guard is a race, not a rule, when two
 * requests can be mid-flight at once (a double-tap, a client retry). Both
 * would read null, both would write, both would emit. So the row is
 * relocked, not merely re-read: [seen] guarantees the row exists first
 * (`insertOrIgnore`, so two *first* requests for the same guide race on
 * inserting the SAME row rather than both trying to create one — the
 * `InventoryMover::ensureBalanceRow` shape), then takes `lockForUpdate()`
 * inside the transaction before testing or setting `completed_at`. A second
 * request finishing the same guide blocks at the database until the first
 * commits, and by then `completed_at` is already set — see
 * `InvitationAcceptanceController::accept` (lock, re-check, act once) and
 * `StaffAccountsController::update`'s docblock: "Read-then-write with
 * nothing in between is not a uniqueness rule, it is a race."
 */
class ProgressController extends Controller
{
    public function __construct(private readonly EventEmitter $events) {}

    /** POST /api/v1/training/learn/guides/{guide}/seen */
    public function seen(Request $request, string $guide): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLearner($user);

        $scope = ViewScope::forUser($user);

        $guideRow = InventoryVisibility::constrain(
            TrainingGuide::query()->where('status', TrainingGuide::PUBLISHED),
            $user,
            $scope,
        )->findOrFail($guide);

        $screenCount = $guideRow->screenCount();

        // An out-of-range or negative screen is refused, not stored — it
        // indexes into `screens`, a JSON array with $screenCount entries. A
        // guide with no screens at all has no valid index, so `max:0` below
        // is paired with an explicit refusal for that case rather than a
        // negative bound doing double duty.
        $input = $request->validate([
            'screen' => ['required', 'integer', 'min:0', 'max:'.max($screenCount - 1, 0)],
        ]);

        if ($screenCount === 0) {
            throw ValidationException::withMessages([
                'screen' => 'This guide has no screens yet, so there is nothing to mark as seen.',
            ]);
        }

        // The validator confirms this is an integer-shaped VALUE, not that
        // it is a PHP int — a JSON body posting `"screen": "7"` (a string)
        // passes `integer` and comes back out of $request->validate() still
        // a string. max()/comparisons below are correct either way, but the
        // stored and returned `last_screen` must not silently pick up the
        // request body's own JSON type.
        $screen = (int) $input['screen'];

        // Topics unlock in order, as they did in A1 Tools: the next one is
        // open once the one before it is complete. Refused here, not only
        // greyed out in the rail, so no client can complete past a lock.
        $reached = TrainingProgress::query()
            ->where('user_id', $user->getKey())
            ->where('guide_id', $guideRow->getKey())
            ->value('last_screen');

        if ($screen > ($reached ?? -1) + 1) {
            throw ValidationException::withMessages([
                'screen' => 'This topic is locked. Complete the topics before it first.',
            ]);
        }

        $progress = DB::transaction(function () use ($user, $guideRow, $screen, $screenCount) {
            // Guarantee the row exists before locking it. Without this, two
            // concurrent FIRST requests for the same guide both see no row,
            // both try to create one, and the unique index on
            // (user_id, guide_id) makes the loser a 500 instead of a race —
            // safe by luck, not by design. insertOrIgnore is idempotent, so
            // both requests converge on the one row insertOrIgnore or the
            // other actually inserted (App\Support\Inventory\InventoryMover
            // ::ensureBalanceRow is the identical shape for a balance row).
            DB::table('training_progress')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'organization_id' => $user->organization_id,
                'user_id' => $user->getKey(),
                'guide_id' => $guideRow->getKey(),
                'last_screen' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Locked, not merely read. "completed_at is still null" has to be
            // a fact this transaction OWNS for the rest of its life, or two
            // requests finishing the same guide at once both read null, both
            // write, and both raise training.completed for one completion —
            // see this class's docblock, InvitationAcceptanceController's
            // lock-then-act, and StaffAccountsController's docblock on why a
            // read-then-write guard is a race and not a rule.
            $progress = TrainingProgress::query()
                ->where('user_id', $user->getKey())
                ->where('guide_id', $guideRow->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // A high-water mark, not a cursor. Re-reading an earlier screen of
            // a guide already finished is ordinary, and must not take the
            // completion away.
            $progress->last_screen = max($progress->last_screen, $screen);

            $justFinished = $progress->last_screen >= $screenCount - 1 && $progress->completed_at === null;

            if ($justFinished) {
                $progress->completed_at = now();
            }

            $progress->save();

            if ($justFinished) {
                // Outbox, never dispatch (StartUp.md §10): this INSERTS a row
                // in the same transaction as the save above, so a failing
                // rule downstream can never fail this request, and the two
                // commit or roll back together.
                $this->events->emit('training.completed', $progress, [
                    'after' => [
                        'guide_id' => $guideRow->getKey(),
                        'user_id' => $user->getKey(),
                        'completed_at' => $progress->completed_at->toIso8601String(),
                    ],
                ]);
            }

            return $progress;
        });

        return response()->json([
            'last_screen' => $progress->last_screen,
            'completed_at' => $progress->completed_at?->toIso8601String(),
            'screen_count' => $screenCount,
        ]);
    }

    private function authorizeLearner(User $user): void
    {
        if (! $user->hasPermission('training.view')) {
            Refusal::permission('record your training progress')->authorize();
        }
    }
}
