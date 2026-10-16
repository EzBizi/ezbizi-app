<?php

namespace App\Support\Dashboard\Glances;

use App\Models\BusinessLocation;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Business Profile box: how many listings, and how many Google is not showing.
 *
 * A snapshot rather than a line, because this is a state and not a flow. There
 * is no useful "listings per day"; there is only "are they up right now", and
 * the number that matters is the one that is not.
 *
 * A suspended listing looks perfectly healthy in a database and is invisible on
 * Maps. Nobody finds out from a chart — they find out when a customer says they
 * could not find the business. That gap is the whole reason this box exists.
 */
final class BusinessProfileGlance extends DescribedGlance
{
    protected const KEY = 'business_profile';
    protected const LABEL = 'Business Profile';
    protected const PERMISSION = 'business_profile.view';
    protected const PRESENTATIONS = ['text'];
    protected const DEFAULT_PRESENTATION = 'text';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $view = ViewScope::forUser($user);

            $locations = BusinessLocation::query()
                ->tap(fn ($q) => $view->constrainPlaces($q))
                ->get(['id', 'verification_state', 'is_published']);

            if ($locations->isEmpty()) {
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
                        'sentence' => 'No Business Profile is connected.',
                    ],
                );
            }

            $total   = $locations->count();
            $notLive = $locations->reject(fn (BusinessLocation $l) => $l->isLive())->count();

            // The bad number leads when there is one. A box that opened with
            // "12 listings" while three of them were invisible would be
            // technically true and useless.
            $sentences = $notLive > 0
                ? [
                    $notLive === 1
                        ? '1 listing is not showing on Google.'
                        : "{$notLive} listings are not showing on Google.",
                    $total === 1 ? '1 listing connected.' : "{$total} listings connected.",
                ]
                : [
                    $total === 1 ? '1 listing, showing.' : "All {$total} listings showing.",
                ];

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: [
                    'empty'     => false,
                    'sentences' => $sentences,
                    // Drawn by the sized dashboard; from the same reach-scoped
                    // collection as the sentences. Snapshot, so no previous.
                    'figures'   => [
                        GlancePayload::figure('listings', 'listings connected', $total),
                        GlancePayload::figure('not_live', 'not showing', $notLive),
                    ],
                    ...($notLive === 0 ? [] : ['attention' => [GlancePayload::attention('bad', $notLive === 1
                        ? '1 listing not showing on Google'
                        : "{$notLive} listings not showing on Google")]]),
                    // `organization_total` was here and is gone. GlancePayload's
                    // rule: a glance sends what its box draws. Nothing in
                    // client/lib read it, so it was a COUNT(*) on every
                    // dashboard load for a figure nobody sees - and an
                    // organization-wide one, so a franchise-scoped reader was
                    // handed the whole organization's listing count in a
                    // response they could open the network tab on. Copied here
                    // rather than decided: the arm carried no argument, which
                    // is the tell. Customers keeps its own because its docblock
                    // makes the case (the book is shared with that reader
                    // anyway); if this box ever needs a figure it does not
                    // draw, the cost is one sentence saying why.
                ],
            );
        });
    }
}
