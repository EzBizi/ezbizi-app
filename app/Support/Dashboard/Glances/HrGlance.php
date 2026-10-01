<?php

namespace App\Support\Dashboard\Glances;

use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Dashboard\Widgets\Hr\ExpiringCertifications;
use App\Support\Dashboard\Widgets\Hr\InvitationsStalled;
use App\Support\Dashboard\Widgets\Hr\OnboardingsInFlight;
use App\Support\Dashboard\Widgets\Hr\ScopesToPlaces;
use App\Support\Dashboard\Widgets\Hr\StartersAndLeavers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Human Resources box: active vs inactive bar (snapshot), five figures as text.
 *
 * Chart counts distinct people in ScopesToPlaces reach, including inactive.
 * Onboardings, stalled invitations, starters/leavers, and certifications stay
 * sentences so text mode keeps the HR plan's five numbers. Places stay
 * ScopesToPlaces — the founder is at organization level, not on a franchise.
 */
final class HrGlance extends DescribedGlance
{
    protected const KEY = 'hr';
    protected const LABEL = 'Human Resources';
    protected const PERMISSION = 'hr.view';
    protected const PRESENTATIONS = ['bar', 'pie', 'text'];
    protected const DEFAULT_PRESENTATION = 'bar';
    protected const SNAPSHOT = true;

    use ScopesToPlaces;

    public function __construct(
        private readonly OnboardingsInFlight $onboardingsInFlight,
        private readonly InvitationsStalled $invitationsStalled,
        private readonly StartersAndLeavers $startersAndLeavers,
        private readonly ExpiringCertifications $expiringCertifications,
    ) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $onTheBooks = $this->peopleOnTheBooks($user);
            $active = (clone $onTheBooks)->where('is_active', true)->count();
            $inactive = (clone $onTheBooks)->where('is_active', false)->count();
            $people = $active + $inactive;

            if ($people === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        // Not "yet", and the reason is that this branch has two
                        // causes it cannot tell apart: an organization with
                        // nobody on the books, and a reader who can reach
                        // nobody. `peopleOnTheBooks` is scoped, so a franchise
                        // manager with no assignments hit the same sentence and
                        // read it as "the company has no staff" — which it was
                        // never entitled to say. A screenshot run caught the
                        // shape once: this box read "nobody" while Customers
                        // listed three of the same tenant's people. The
                        // sentence describes the BOX (UI/UX, 2026-09-09;
                        // sixteen strings, no exemptions), and being true of
                        // both causes is what makes it honest rather than
                        // merely softer.
                        'sentence' => 'Nobody in this view.',
                    ],
                );
            }

            $inFlight = $this->onboardingsInFlight->resolve($user, []);
            $stalled = $this->invitationsStalled->resolve($user, []);
            $starters = $this->startersAndLeavers->resolve($user, [
                // In the VIEW timezone, not UTC. Joined and Left are DATES now
                // (the first `started` event, a `left` event), recorded in the
                // organization's own days, so the bound is the period's first
                // local day. A UTC bound would name the previous day for every
                // organization east of UTC. (Until 2026-10-01 Joined read
                // `users.created_at`, which is UTC, and ->utc() was right.)
                'since' => $period->startsOn->copy(),
            ]);
            // Forward window of its own — not the dashboard period.
            $certifications = $this->expiringCertifications->resolve($user, []);

            $inFlightTotal = (int) ($inFlight['total'] ?? 0);
            $stalledTotal = (int) ($stalled['total'] ?? 0);

            $slices = [
                ['label' => 'Active', 'total' => $active],
                ['label' => 'Inactive', 'total' => $inactive],
            ];

            $periodWord = $period->phrase();
            $joined = (int) (collect($starters['rows'] ?? [])->firstWhere('title', 'Joined')['total'] ?? 0);
            $left = (int) (collect($starters['rows'] ?? [])->firstWhere('title', 'Left')['total'] ?? 0);

            $sentences = [
                $people === 1
                    ? '1 person on the books.'
                    : "{$people} people on the books.",
                $inFlightTotal === 1
                    ? '1 onboarding in flight.'
                    : "{$inFlightTotal} onboardings in flight.",
                $stalledTotal === 1
                    ? '1 invitation has stalled.'
                    : "{$stalledTotal} invitations have stalled.",
                $joined === 1
                    ? "1 person joined {$periodWord}."
                    : "{$joined} people joined {$periodWord}.",
                $left === 1
                    ? "1 person left {$periodWord}."
                    : "{$left} people left {$periodWord}.",
            ];

            if (! empty($starters['note'])) {
                $sentences[] = $starters['note'];
            }

            if (! empty($certifications['note'])) {
                $sentences[] = $certifications['note'];
            } else {
                // Zero renders, like the five lines above it. It used to be the
                // only one that vanished when the news was good, so a reader
                // could not tell "nothing is coming due" from "nobody is
                // watching" — and absence is the one thing a box cannot use to
                // say it looked (UI/UX, 602e9716). The note above is the other
                // branch, for when there is genuinely nothing to count.
                //
                // BOTH QUALIFIERS, and I put this line in without either of
                // them an hour before Marketing's finding arrived (their
                // 011f54a5, carried by the desk). At zero this is an ALL-CLEAR,
                // and an all-clear that omits its bounds is worse than an empty
                // state that does: an overclaiming empty state sends somebody
                // to look, an overclaiming all-clear stops them looking. The
                // query is scoped by placesInView AND windowed, so the sentence
                // says "in this view" and names the window rather than implying
                // it checked everywhere, forever.
                //
                // "has lapsed or expires within" rather than "is coming due",
                // because the row set is not only the future: the widget keeps
                // anything that expired within the past year alongside anything
                // expiring inside the window. "Coming due" quietly reclassified
                // a licence that ran out in March as something still ahead of
                // them.
                $certTotal = (int) ($certifications['total'] ?? 0);
                $days = (int) ($certifications['days'] ?? 60);

                $sentences[] = match (true) {
                    $certTotal === 0 => "Nothing in this view has lapsed or expires within {$days} days.",
                    $certTotal === 1 => "1 certification has lapsed or expires within {$days} days.",
                    default => "{$certTotal} certifications have lapsed or expire within {$days} days.",
                };
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: [
                    'empty' => false,
                    'slices' => $slices,
                    'sentences' => $sentences,
                ],
            );
        });
    }

    /**
     * Distinct people Headcount would consider, including inactive.
     *
     * Nested so unplaced people (organization view only) cannot invert into
     * "assigned nowhere means see everybody".
     */
    private function peopleOnTheBooks(User $user): Builder
    {
        $places = $this->placesInView($user);
        $withUnplaced = $this->countsUnplaced($places);

        return User::query()->where(function (Builder $visible) use ($places, $withUnplaced) {
            $visible->whereHas(
                'assignments',
                fn (Builder $a) => $this->constrainToPlaces($a, $places),
            );

            if ($withUnplaced) {
                $visible->orWhereDoesntHave('assignments');
            }
        });
    }
}
