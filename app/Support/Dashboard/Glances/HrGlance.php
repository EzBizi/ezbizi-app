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
                // ->utc(), because startsOn is a wall clock in the VIEW timezone
                // and `created_at` is UTC. A Carbon binds as its own local
                // string with no conversion, so a UTC-5 organization compared
                // UTC timestamps against Eastern strings and the bound sat five
                // hours EARLY — pulling in starters from about 19:00 the
                // previous evening. Seven sibling glances already convert;
                // JobsGlance:107 carries the rule. This one did not.
                //
                // Note the direction: this is a lower bound only, so it
                // OVER-COUNTS. CallReport's version of the same defect was a
                // two-sided whereBetween binding late and DROPPING evening
                // calls. A test written for one will not catch the other.
                'since' => $period->startsOn->copy()->utc(),
            ]);
            // Forward window of its own — not the dashboard period.
            $certifications = $this->expiringCertifications->resolve($user, []);

            $inFlightTotal = (int) ($inFlight['total'] ?? 0);
            $stalledTotal = (int) ($stalled['total'] ?? 0);

            $slices = [
                ['label' => 'Active', 'total' => $active],
                ['label' => 'Inactive', 'total' => $inactive],
            ];

            $periodWord = $period->key;
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
                    ? "1 person joined this {$periodWord}."
                    : "{$joined} people joined this {$periodWord}.",
                $left === 1
                    ? "1 person left this {$periodWord}."
                    : "{$left} people left this {$periodWord}.",
            ];

            if (! empty($starters['note'])) {
                $sentences[] = $starters['note'];
            }

            if (! empty($certifications['note'])) {
                $sentences[] = $certifications['note'];
            } elseif (($certTotal = (int) ($certifications['total'] ?? 0)) > 0) {
                $sentences[] = $certTotal === 1
                    ? '1 certification is coming due.'
                    : "{$certTotal} certifications are coming due.";
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
