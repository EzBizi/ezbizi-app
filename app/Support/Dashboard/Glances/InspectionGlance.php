<?php

namespace App\Support\Dashboard\Glances;

use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Inspection\InspectionScope;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Inspection box: named inspections as bars (snapshot), status as sentences.
 *
 * One bar per name with a live count: in-progress runs (definition name) plus
 * submitted reports that are not archived (frozen definition_name). Archived
 * and submitted-this-period stay sentences. Reach copies Generated Reports.
 */
final class InspectionGlance extends DescribedGlance
{
    protected const KEY = 'inspection';
    protected const LABEL = 'Inspection';
    protected const PERMISSION = 'inspection.view';
    protected const PRESENTATIONS = ['bar', 'pie', 'text'];
    protected const DEFAULT_PRESENTATION = 'bar';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $reachable = collect(ViewScope::forUser($user)->franchiseIds());

            $runs = InspectionRun::query()
                ->where(fn (Builder $q) => $this->constrainToReach($q, $reachable))
                ->with('definition:id,name')
                ->get();
            $liveReports = InspectionReport::query()
                ->whereNull('archived_at')
                ->where(fn (Builder $q) => $this->constrainToReach($q, $reachable))
                ->get(['id', 'definition_name']);
            $archived = InspectionReport::query()
                ->whereNotNull('archived_at')
                ->where(fn (Builder $q) => $this->constrainToReach($q, $reachable))
                ->count();

            $inProgress = $runs->count();
            $submitted = $liveReports->count();

            if ($inProgress === 0 && $submitted === 0 && $archived === 0) {
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
                        'sentence' => 'No inspections in reach.',
                    ],
                );
            }

            // Period bounds are wall times in the view timezone; submitted_at is
            // stored UTC. Bind UTC instants so "now" is not compared as a naive
            // clock string earlier than the row.
            $from = $period->startsOn->copy()->utc();
            $to = $period->endsOn->copy()->utc();
            $submittedThisPeriod = InspectionReport::query()
                ->where(fn (Builder $q) => $this->constrainToReach($q, $reachable))
                ->where('submitted_at', '>=', $from)
                ->where('submitted_at', '<=', $to)
                ->count();

            $periodWord = $period->key;

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
                    'slices' => $this->namedSlices($runs, $liveReports),
                    'sentences' => [
                        "{$inProgress} in progress.",
                        "{$submittedThisPeriod} submitted this {$periodWord}.",
                        "{$archived} archived.",
                    ],
                ],
            );
        });
    }

    /**
     * One bar per name that has a live count. Labels sort so the board stays put.
     *
     * @param  Collection<int, InspectionRun>  $runs
     * @param  Collection<int, InspectionReport>  $reports
     * @return list<array{label: string, total: int}>
     */
    private function namedSlices(Collection $runs, Collection $reports): array
    {
        $counts = [];

        foreach ($runs as $run) {
            $name = (string) ($run->definition?->name ?? '');
            if ($name === '') {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        foreach ($reports as $report) {
            $name = (string) ($report->definition_name ?? '');
            if ($name === '') {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        ksort($counts, SORT_STRING);

        $slices = [];
        foreach ($counts as $label => $total) {
            $slices[] = ['label' => $label, 'total' => $total];
        }

        return $slices;
    }

    /**
     * The one franchise filter, shared with InspectionReportsController::index.
     *
     * This was a COPY of the controller's, with a docblock saying so. The copy
     * carried the controller's bug — a record with no job was counted for every
     * branch in the organization — and the comment claiming the two matched is
     * why the second one was never re-read. It delegates now, so they cannot
     * drift apart again.
     *
     * Applied to runs as well as reports, which is sound because both models
     * carry the same `job` and `customer` relations; the shared method asks
     * only for those.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $reachable
     */
    private function constrainToReach(Builder $query, $reachable): void
    {
        InspectionScope::constrainReports($query, $reachable->all());
    }
}
