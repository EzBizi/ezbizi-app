<?php

namespace App\Support\Dashboard\Glances;

use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inspection box: in progress / submitted / archived (snapshot).
 *
 * Reach copies Generated Reports: job in the view's franchise ids, or no job.
 * Empty reach still matches job-less rows the same way the list does.
 */
final class InspectionGlance implements Glance
{
    public function key(): string
    {
        return 'inspection';
    }

    public function label(): string
    {
        return 'Inspection';
    }

    public function permission(): string
    {
        return 'inspection.view';
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
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $reachable = collect(ViewScope::forUser($user)->franchiseIds());

            $inProgress = InspectionRun::query()
                ->where(fn (Builder $q) => $this->constrainToReach($q, $reachable))
                ->count();
            $submitted = InspectionReport::query()
                ->whereNull('archived_at')
                ->where(fn (Builder $q) => $this->constrainToReach($q, $reachable))
                ->count();
            $archived = InspectionReport::query()
                ->whereNotNull('archived_at')
                ->where(fn (Builder $q) => $this->constrainToReach($q, $reachable))
                ->count();

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
                    'slices' => [
                        ['label' => 'In progress', 'total' => $inProgress],
                        ['label' => 'Submitted', 'total' => $submitted],
                        ['label' => 'Archived', 'total' => $archived],
                    ],
                ],
            );
        });
    }

    /**
     * Same franchise filter as InspectionReportsController::index.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $reachable
     */
    private function constrainToReach(Builder $query, $reachable): void
    {
        $query->whereNull('job_id')
            ->orWhereHas('job', fn (Builder $jobs) => $jobs->withTrashed()->whereIn('franchise_id', $reachable));
    }
}
