<?php

namespace App\Support\Dashboard\Glances;

use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Access\ScopeReach;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Training\LapsedPasses;
use Illuminate\Database\Eloquent\Builder;

/**
 * Training box: ONE number — how many people are due to retake a test.
 *
 * ── Why one count and not a summary ─────────────────────────────────────
 *
 * Training already has a dashboard inside its own tab, and the standing
 * objection to a home-screen box was that a second summary one click from the
 * real one restates it and then drifts. UI/UX's ruling (2026-09-09) is that
 * the objection is about a SUMMARY and does not apply to a COUNT: a single
 * number cannot drift from the in-tab dashboard because it is not describing
 * it. So this carries the count and nothing else — no assignment list, no
 * scores, no names.
 *
 * The count chosen is the one that is invisible today. "Due for retaking" is
 * shown one person at a time: HR's record card names it for the employee in
 * front of you, and the Training dashboard names it once you have opened the
 * tab and gone looking. Nobody sees the organization-wide figure at all,
 * which is exactly what a glance is for.
 *
 * ── It counts PEOPLE, and this is the trap it exists to avoid ───────────
 *
 * `DashboardController`'s own docblock warns about this box by name: summing
 * that endpoint's per-row `retaking` counts ASSIGNMENTS, so one person lapsed
 * on three tests reads as 3, and a home-screen box saying "3" sends somebody
 * looking for three people who do not exist. This counts DISTINCT people, the
 * same way `summary.people_due_for_retaking` does.
 *
 * ── Two scopes, and the second one is the one that leaks ────────────────
 *
 * Content scope ([InventoryVisibility]) decides which tests are in reach.
 * Person reach ([ScopeReach::constrainToVisiblePeople]) decides whose lapse
 * may be counted, and it is a separate question: an organization-wide test is
 * visible to everybody, so content scope constrains nothing about the PEOPLE
 * who sat it. This module has already shipped two cross-franchise disclosures
 * of named people's results by conflating the two (task-11 review), and a
 * count is a disclosure too — "4 people are due" tells a franchise manager
 * about people they may not see. Both are applied here, in that order, and
 * `TrainingGlanceTest` pins the sibling-franchise case.
 *
 * `training.monitor`, never `training.view`: `view` is the LEARNER'S
 * permission, and an organization-wide headcount is not a learner's fact.
 * `DashboardController::authorizeMonitor` gates the in-tab equivalent the
 * same way.
 *
 * SNAPSHOT: "due for retaking" means ALREADY LAPSED, right now — never a
 * lookahead, never a window (see `DashboardController`, "`retaking` has no
 * lookahead, on purpose"). A period chip has nothing to change about it, so
 * the box ignores them.
 *
 * The word "certification" never appears here, for the reason
 * `DashboardController` gives at length: HR's own panel counts licence dates
 * the EMPLOYEE renews, this counts our test passes lapsing, and the two sit
 * on the same screen.
 */
final class TrainingGlance extends DescribedGlance
{
    protected const KEY = 'training';
    protected const LABEL = 'Training';
    protected const PERMISSION = 'training.monitor';
    protected const PRESENTATIONS = ['text'];
    protected const DEFAULT_PRESENTATION = 'text';
    protected const SNAPSHOT = true;

    public function __construct(private readonly ScopeReach $reach) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);

            $testIds = InventoryVisibility::constrain(
                TrainingTest::query()->where('status', TrainingTest::PUBLISHED),
                $user,
                $scope,
            )->pluck('id')->all();

            if ($testIds === []) {
                return $this->payload([
                    'empty' => true,
                    // THE EMPTY SENTENCE DESCRIBES THE BOX, NOT THE WORLD.
                    // That is the rule, and it is why this line is worded the
                    // way it is: "No tests to track." says what this box holds,
                    // which is true under any scope. "No tests in reach." and
                    // "No tests yet." both make a claim about the ORGANIZATION
                    // — that none exist — and a box showing somebody their own
                    // subset cannot support that claim.
                    //
                    // This string moved THREE TIMES on 2026-09-09 and the
                    // reasoning is here so a fourth reader does not move it
                    // again. It was "in reach", changed to "to track" when a
                    // measurement found "reach" absent from customer copy,
                    // changed back when that measurement turned out to have
                    // searched only ui_strings.dart (glance copy is PHP, and
                    // "in reach" was in eleven files), and settled here when a
                    // fuller count found the same board ALSO writes the plain
                    // form — Leads says "No enquiries yet.", Reviews says "No
                    // reviews yet." Eleven files saying a thing makes it
                    // common, not conventional. UI/UX then ruled fleet-wide
                    // that the scoped counts drop "in reach" (2026-09-09).
                    //
                    // The word is NOT banned: the automations failure detail
                    // and the builder's action description keep it, because
                    // there it names the mechanism being explained to somebody
                    // configuring a rule, and dropping it would change the
                    // meaning to "no franchise exists". Different reader,
                    // different job.
                    'sentence' => 'No tests to track.',
                ]);
            }

            // Zero is NOT empty once tests exist — the same rule FleetGlance
            // states for vehicles. "Nobody is due" is the good news this box
            // is for, and a box that vanished at zero would take the board's
            // layout with it every time the last person retook something.
            $due = $this->peopleDueForRetaking($testIds, $user);

            // The same distinct-PEOPLE count the sentence carries, never a sum
            // of assignments; the tests are the ones this box tracks. Already
            // lapsed, right now: a snapshot, so no previous.
            return $this->payload([
                'empty' => false,
                'sentence' => $this->sentenceFor($due),
                'figures' => [
                    GlancePayload::figure('due', 'due to retake', $due),
                    GlancePayload::figure('tests', 'tests tracked', count($testIds)),
                ],
            ] + ($due > 0 ? [
                // A lapsed pass is already overdue: `bad`. Nothing is
                // "about to" lapse here — the box has no lookahead, on purpose.
                'attention' => [GlancePayload::attention('bad', $this->sentenceFor($due))],
            ] : []));
        });
    }

    /**
     * DISTINCT people holding a lapsed pass on one of $testIds, bounded by
     * reach over the PERSON.
     *
     * [LapsedPasses::currentlyLapsedQuery] is the same single definition of
     * "lapsed" the nightly sweep, the in-tab dashboard and the Learn read all
     * ask — the point of that class — so this box cannot disagree with them
     * about who is due.
     *
     * @param  list<string>  $testIds
     */
    private function peopleDueForRetaking(array $testIds, User $user): int
    {
        $candidates = LapsedPasses::currentlyLapsedQuery($testIds)
            ->distinct()
            ->pluck('training_attempts.user_id')
            ->all();

        if ($candidates === []) {
            return 0;
        }

        return User::query()
            ->whereIn('id', $candidates)
            ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $user))
            ->count();
    }

    private function sentenceFor(int $due): string
    {
        return match ($due) {
            0 => 'Nobody is due to retake a test.',
            1 => '1 person is due to retake a test.',
            default => "{$due} people are due to retake a test.",
        };
    }
}
