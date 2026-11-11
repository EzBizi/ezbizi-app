<?php

namespace App\Support\Reports\Experimental;

use App\Http\Controllers\Api\ExperimentalCallReportController;
use App\Models\CallReportConversion;
use App\Models\CallReportDispatcherTag;
use App\Models\User;
use App\Support\Reports\ReportJobs;
use App\Support\Reports\ReportView;
use Carbon\CarbonImmutable;

/**
 * Performance's People rows for a period, built from the same inputs as
 * ExperimentalCallReportController::show, so Scoring's conversion rate is the
 * number Performance shows (Jose, 2026-10-14). Change one, change both.
 */
class PerformancePeople
{
    public function __construct(
        private RingCentralCallLog $calls,
        private WorkizJobs $workiz,
        private CallReconciliation $reconcile,
    ) {}

    /**
     * $connection reads that RingCentral account alone; null, the default one.
     * $branches: only those branches' Workiz jobs; null, every one in view.
     *
     * @param  list<string>|null  $branches
     * @return array<string, array<string, mixed>> person => row; empty without RingCentral
     */
    public function for(?User $user, CarbonImmutable $start, CarbonImmutable $end, ?string $connection = null, ?array $branches = null): array
    {
        $this->calls->only($connection);
        try {
            return $this->people($start, $end, $branches);
        } finally {
            $this->calls->only(null);
        }
    }

    /** @return array<string, array<string, mixed>> */
    /** @param  list<string>|null  $branches */
    private function people(CarbonImmutable $start, CarbonImmutable $end, ?array $branches): array
    {
        if (! $this->calls->configured()) {
            return [];
        }

        // Jobs created as every report counts them, less the tags left out (2026-11-10).
        $jobs = $this->workiz->allAccounts() !== []
            ? ReportJobs::created(collect($this->workiz->storedAll($start->subDays(ReportView::lookbackDays())->toDateString(), $branches)['jobs']))->all()
            : [];

        $report = $this->reconcile->forRange(
            $this->calls->forPeriod($start, $end, outbound: true),
            $jobs,
            $start->toDateString(),
            $end->toDateString(),
            CallReportConversion::query()->whereBetween('call_started_at', [$start->utc(), $end->utc()])->pluck('call_id')->all(),
            CallReportDispatcherTag::query()->pluck('tag', 'person')->all(),
        );

        return array_column($report['people'], null, 'person');
    }
}
