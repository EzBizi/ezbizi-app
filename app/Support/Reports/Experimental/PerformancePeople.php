<?php

namespace App\Support\Reports\Experimental;

use App\Http\Controllers\Api\ExperimentalCallReportController;
use App\Models\CallReportConversion;
use App\Models\CallReportDispatcherTag;
use App\Models\User;
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

    /** @return array<string, array<string, mixed>> person => row; empty without RingCentral */
    public function for(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! $this->calls->configured()) {
            return [];
        }

        $jobs = $this->workiz->configured($user)
            ? $this->workiz->stored($user, $start->subDays(ExperimentalCallReportController::LOOKBACK_DAYS)->toDateString())['jobs']
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
