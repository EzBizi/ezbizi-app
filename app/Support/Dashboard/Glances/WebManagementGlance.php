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
                    ->get(['id', 'connected_at', 'went_quiet_at']);

            if ($sites->isEmpty()) {
                return $this->payload([
                    'empty' => true,
                    /*
                     * "No websites yet.", not "No websites are set up yet."
                     *
                     * This query is scoped by company_id, so a franchise reader
                     * sees a subset — and "none are set up" is a claim about
                     * the whole organization that this box cannot support. The
                     * count in the box and the count in the business are two
                     * different facts, and only the first one is ours to state.
                     *
                     * This used to cite `organization_total`, which the branch
                     * below sent, as proof the module knew the difference. That
                     * field is gone (see the note there) and the reasoning never
                     * depended on it.
                     *
                     * "to show", not "yet": the ruling took BOTH words. All
                     * six "yet" boxes were scoped too, so there was no
                     * exemption to grant, and PhoneGlance already said "No
                     * phone lines to show." — the form was on the board before
                     * the rule was written.
                     */
                    'sentence' => 'No websites to show.',
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
                     * qualified nothing a reader could parse — UI/UX's ruling
                     * of 2026-09-09 (b543a45a).
                     *
                     * The replacement is a PARTICIPLE, not a bare count. The
                     * bare "N websites." I was offered would have been the only
                     * bare count on the board: Services measured all 23 count
                     * sentences across 13 glances and every one carries a
                     * qualifier. "registered" is this module's own verb — a
                     * company registers each host it owns.
                     */
                    $total === 1 ? '1 website registered.' : "{$total} websites registered.",
                ]
                : [
                    $total === 1 ? '1 website, connected.' : "All {$total} websites connected.",
                ];

            /*
             * NO `organization_total`, removed 2026-09-17 on Dashboard's rule:
             * a glance sends what its box draws and nothing else.
             *
             * It had no reader. `grep -rn organization_total client/lib
             * client/test` found nothing — five glances were sending it and the
             * client read it in none of them, which is the tell that it was
             * copied rather than decided. A figure nobody draws is a query on
             * every dashboard load and a disclosure on every response, bought
             * for nothing.
             *
             * WHAT IT DISCLOSED, since the key name does not say. The rest of
             * this method is scoped by `company_id` narrowed by ViewScope, so a
             * franchise reader sees their own sites. That line was
             * `WebSite::query()->count()` with neither filter — every site in
             * the organization. Not across tenants: WebSite is BelongsToTenant
             * and this runs inside TenantContext::run, so the global scope held
             * and the 110 sites of other companies were never in it. The
             * exposure was one organization wide, invisible on screen and
             * plainly readable in the response.
             *
             * CustomersGlance keeps its copy and is the real exception: §5.2
             * shares the book, so the figure is not withheld from that reader
             * anyway, and its docblock argues the case. If this box ever needs
             * a number it does not draw, the cost is one sentence saying why —
             * here, at the line that sends it.
             */
            /*
             * The rows that need somebody (Dashboard, 2026-10-05). A site that
             * STOPPED reading is broken: it is showing whatever it last read to
             * every visitor, and the quiet sweep has already decided it is
             * quiet (`went_quiet_at`), so this box agrees with the event a rule
             * would get. A site never connected is unfinished, not broken.
             */
            $quiet = $sites->whereNotNull('went_quiet_at')->count();
            $attention = array_values(array_filter([
                $quiet > 0 ? GlancePayload::attention('bad', $quiet === 1
                    ? '1 website stopped reading EzBizi.'
                    : "{$quiet} websites stopped reading EzBizi.") : null,
                $waiting > 0 ? GlancePayload::attention('caution', $waiting === 1
                    ? '1 website is waiting for its plugin.'
                    : "{$waiting} websites are waiting for their plugin.") : null,
            ]));

            return $this->payload([
                'empty' => false,
                'sentences' => $sentences,
                // Only when something needs somebody.
                ...($attention === [] ? [] : ['attention' => $attention]),
                // Drawn by the sized dashboard, and built from the same scoped
                // collection as the sentences, so no organization-wide count.
                'figures' => [
                    GlancePayload::figure('sites', 'websites', $total),
                    GlancePayload::figure('waiting', 'waiting for plugin', $waiting),
                ],
            ]);
        });
    }
}
