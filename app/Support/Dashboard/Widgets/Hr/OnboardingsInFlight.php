<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paperwork that has been started and not finished
 * (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * ── The two states, and why they are not one number ──────────────
 *
 * An onboarding sits in one of two unfinished states, and they need different
 * people to do different things:
 *
 *   in_progress  somebody is still filling it in — chase whoever has not typed
 *   submitted    it is complete and one side has signed — chase the other side
 *
 * Reported as one "8 in progress" they would be indistinguishable, and the
 * commonest real failure in this module is a contract sitting signed by the
 * employer with nobody having told the employee it is waiting for them.
 *
 * ── Reach comes through the invitation ───────────────────────────
 *
 * `onboardings` carries no scope columns of its own; the placement was decided
 * on the invitation, which is the only table on this path with the three
 * `assigned_*` columns. That is the rule HrOnboardingsController::index already
 * uses, copied deliberately so the panel and the list it summarises cannot
 * disagree about who can see what.
 */
class OnboardingsInFlight
{
    use ScopesToPlaces;

    public function resolve(User $user, array $options): array
    {
        // No period. An onboarding is unfinished or it is not, and a period
        // would hide the oldest one — which is the one that needs chasing and
        // the whole reason to look.
        $places = $this->placesInView($user);

        $counts = $this->inFlight($places)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Both states always appear, including at zero, for the reason
        // JobsByStatus gives: a list that hides its empty rows reorders itself
        // as work moves, which is precisely when somebody is reading it.
        $rows = [
            ['title' => 'Being filled in', 'total' => (int) ($counts[Onboarding::IN_PROGRESS] ?? 0)],
            ['title' => 'Waiting on a signature', 'total' => (int) ($counts[Onboarding::SUBMITTED] ?? 0)],
            ['title' => 'Waiting for approval', 'total' => (int) ($counts[Onboarding::AWAITING_APPROVAL] ?? 0)],
        ];

        $oldest = $this->inFlight($places)->min('started_at');

        return [
            'rows'  => $rows,
            'total' => (int) $counts->sum(),

            // How long the longest-running one has been open. The number that
            // turns "three in flight" into "one of these has been open since
            // March", which is the sentence somebody acts on.
            'oldest_started_at' => $oldest === null
                ? null
                : \Illuminate\Support\Carbon::parse($oldest)->toIso8601String(),
        ];
    }

    /** Started, not finished, and within this view. */
    private function inFlight(array $places): Builder
    {
        return Onboarding::query()
            ->whereIn('status', Onboarding::OPEN)
            ->where(function (Builder $visible) use ($places) {
                $visible->whereHas(
                    'invitation',
                    fn (Builder $i) => $this->constrainToPlaces($i, $places),
                );

                /*
                 * An onboarding with no invitation is visible only from the
                 * organization.
                 *
                 * Nothing creates one today — store() requires an invitation —
                 * so this is the conservative reading held ready for the
                 * second-contract-for-an-existing-employee case, and it matches
                 * HrOnboardingsController exactly. Visible from the top,
                 * invisible below it, rather than visible to everybody because
                 * nothing narrower could be worked out.
                 */
                if ($places['organization'] !== null) {
                    $visible->orWhereNull('invitation_id');
                }
            });
    }
}
