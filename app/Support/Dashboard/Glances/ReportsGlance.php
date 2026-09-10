<?php

namespace App\Support\Dashboard\Glances;

use App\Models\SavedReport;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Reports box: how many saved reports this view can run.
 *
 * A snapshot rather than a period series. A report either exists or it does
 * not, and "reports created this month" is a question nobody asks — the period
 * selector would be a control that changes nothing, which is worse than its
 * absence.
 */
final class ReportsGlance extends DescribedGlance
{
    protected const KEY = 'reports';
    protected const LABEL = 'Reports';
    protected const PERMISSION = 'reports.view';
    protected const PRESENTATIONS = ['text'];
    protected const DEFAULT_PRESENTATION = 'text';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $view = ViewScope::forUser($user);

            $total = SavedReport::query()
                ->unless($view->isOrganization(), function ($query) use ($view) {
                    $companyIds = $view->companyIds();

                    if ($companyIds !== []) {
                        $query->whereIn('company_id', $companyIds);
                    }
                })
                ->count();

            if ($total === 0) {
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
                        // One sentence, singular key: `empty: true` renders
                        // exactly one and silently drops any others, so a
                        // second sentence added here would vanish with no error.
                        'sentence' => 'No reports built yet. Open the Reports tab to build one.',
                    ],
                );
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
                    'sentences' => [
                        $total === 1 ? '1 report saved.' : "{$total} reports saved.",
                    ],
                ],
            );
        });
    }
}
