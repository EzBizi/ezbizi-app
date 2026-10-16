<?php

namespace App\Support\Dashboard\Glances;

use App\Models\SavedReport;
use App\Models\User;
use App\Support\Automations\ReportSenders;
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

            $ids = SavedReport::query()
                // Downwards only: a place view lists its company's reports, never a
                // sibling's or the organization's (2026-10-16).
                ->when($view->companyIdsInView() !== null, fn ($query) => $query->whereIn('company_id', $view->companyIdsInView()))
                ->pluck('id')
                ->all();

            $total = count($ids);

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
                    'figures' => [
                        GlancePayload::figure('saved', 'reports saved', $total),
                    ],
                    ...$this->attention($ids),
                ],
            );
        });
    }

    /**
     * The one thing in Reports that needs somebody: a scheduled report that
     * STOPPED. An automation switches itself off after five refusals in a row
     * (Automation::FAILURE_LIMIT), and the report then simply stops arriving -
     * nobody is told, because the thing that would tell them is what stopped.
     *
     * Counted, never named: rule names are Automations' data and this box is
     * shown to anyone holding reports.view. A report another rule still sends
     * is not stopped and is not counted.
     *
     * @param  list<string>  $ids
     * @return array{attention?: list<array{tone: string, text: string}>}
     */
    private function attention(array $ids): array
    {
        $stopped = 0;

        foreach (ReportSenders::for(ReportSenders::REPORT_SAVED, $ids) as $rules) {
            $sending = collect($rules)->contains(fn (array $r) => $r['is_active'] && $r['disabled_at'] === null);
            $switchedOff = collect($rules)->contains(fn (array $r) => $r['disabled_at'] !== null);

            if ($switchedOff && ! $sending) {
                $stopped++;
            }
        }

        if ($stopped === 0) {
            return [];
        }

        return ['attention' => [GlancePayload::attention(
            'bad',
            $stopped === 1
                ? '1 scheduled report stopped sending: its automation switched itself off.'
                : "{$stopped} scheduled reports stopped sending: their automations switched themselves off.",
        )]];
    }
}
