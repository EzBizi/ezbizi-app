<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Company;
use App\Models\User;
use App\Models\WebSite;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Web Management box: how many websites, and how many are not reading.
 *
 * A snapshot, for the reason Business Profile gives about listings — this is a
 * state, not a flow, and there is no useful "websites per day".
 *
 * ── The number that matters is the one that is silent ──
 *
 * A site whose plugin has stopped reading looks perfectly healthy here and is
 * showing last month's phone number to every visitor. Nobody finds that out
 * from a chart; they find out when somebody rings the old number. So an
 * unconnected site leads the box, and the total follows it.
 *
 * This box replaced a coming-soon placeholder on 2026-08-28 whose sentence
 * promised a website editor. That was never what got built and the sentence had
 * become false, which on a dashboard is worse than an empty space.
 */
final class WebManagementGlance extends DescribedGlance
{
    protected const KEY = 'web_management';
    protected const LABEL = 'Web Management';
    protected const PERMISSION = 'web_management.view';
    protected const PRESENTATIONS = ['text'];
    protected const DEFAULT_PRESENTATION = 'text';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);

            // Same reach question the SEO tab's list asks, and the same answer:
            // hard company reach, narrowed by the view. A franchise-only user
            // does not manage the brand's domain, so they see no sites here.
            $companyIds = $user->reachableCompanies()
                ->filter(fn (Company $company) => $scope->coversCompany($company->id))
                ->pluck('id')
                ->all();

            $sites = $companyIds === []
                ? collect()
                : WebSite::query()
                    ->whereIn('company_id', $companyIds)
                    ->get(['id', 'connected_at']);

            if ($sites->isEmpty()) {
                return $this->payload([
                    'empty' => true,
                    /*
                     * "No websites yet.", not "No websites are set up yet."
                     *
                     * This query is scoped by company_id, so a franchise reader
                     * sees a subset — and "none are set up" is a claim about
                     * the whole organization that this box cannot support. The
                     * branch below already proves the module knows the
                     * difference: it sends `organization_total` separately,
                     * because the count in the box and the count in the
                     * business are two different facts.
                     *
                     * The bare form is also what the board already says next
                     * door: LeadsGlance "No enquiries yet.", ReviewsGlance
                     * "No reviews yet."
                     */
                    'sentence' => 'No websites yet.',
                ]);
            }

            $total = $sites->count();
            $waiting = $sites->whereNull('connected_at')->count();

            $sentences = $waiting > 0
                ? [
                    $waiting === 1
                        ? '1 website is waiting for its plugin.'
                        : "{$waiting} websites are waiting for their plugin.",
                    /*
                     * The sentence describes the BOX, not the world. The number
                     * already says exactly what the box holds, so "in reach"
                     * qualified nothing — UI/UX's ruling of 2026-09-09
                     * (b543a45a), which reversed their own earlier one after
                     * measuring that the board already wrote the plain form
                     * beside it.
                     */
                    $total === 1 ? '1 website.' : "{$total} websites.",
                ]
                : [
                    $total === 1 ? '1 website, connected.' : "All {$total} websites connected.",
                ];

            return $this->payload([
                'empty' => false,
                'sentences' => $sentences,
                'organization_total' => WebSite::query()->count(),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: $data,
        );
    }
}
