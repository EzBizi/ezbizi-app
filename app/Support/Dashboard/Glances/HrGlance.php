<?php

namespace App\Support\Dashboard\Glances;

use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Dashboard\Widgets\Hr\ExpiringCertifications;
use App\Support\Dashboard\Widgets\Hr\Headcount;
use App\Support\Dashboard\Widgets\Hr\InvitationsStalled;
use App\Support\Dashboard\Widgets\Hr\OnboardingsInFlight;
use App\Support\Dashboard\Widgets\Hr\StartersAndLeavers;
use App\Support\Tenancy\TenantContext;

/**
 * Human Resources box: in-flight vs stalled bar (snapshot), five figures as text.
 *
 * Chart reuses OnboardingsInFlight and InvitationsStalled totals. Headcount,
 * starters/leavers, and certifications stay sentences so text mode keeps the
 * HR plan's five numbers. Places stay ScopesToPlaces — the founder is at
 * organization level, not on a franchise.
 */
final class HrGlance implements Glance
{
    public function __construct(
        private readonly Headcount $headcount,
        private readonly OnboardingsInFlight $onboardingsInFlight,
        private readonly InvitationsStalled $invitationsStalled,
        private readonly StartersAndLeavers $startersAndLeavers,
        private readonly ExpiringCertifications $expiringCertifications,
    ) {}

    public function key(): string
    {
        return 'hr';
    }

    public function label(): string
    {
        return 'Human Resources';
    }

    public function permission(): string
    {
        return 'hr.view';
    }

    public function allowedPresentations(): array
    {
        return ['bar', 'pie', 'text'];
    }

    public function defaultPresentation(): string
    {
        return 'bar';
    }

    public function snapshot(): bool
    {
        return true;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $headcount = $this->headcount->resolve($user, []);
            $people = (int) ($headcount['total'] ?? 0);

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
                        'sentence' => 'Nobody on the books yet.',
                    ],
                );
            }

            $inFlight = $this->onboardingsInFlight->resolve($user, []);
            $stalled = $this->invitationsStalled->resolve($user, []);
            $starters = $this->startersAndLeavers->resolve($user, [
                'since' => $period->startsOn,
            ]);
            // Forward window of its own — not the dashboard period.
            $certifications = $this->expiringCertifications->resolve($user, []);

            $inFlightTotal = (int) ($inFlight['total'] ?? 0);
            $stalledTotal = (int) ($stalled['total'] ?? 0);

            $slices = [
                ['label' => 'Onboardings in flight', 'total' => $inFlightTotal],
                ['label' => 'Invitations stalled', 'total' => $stalledTotal],
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
}
