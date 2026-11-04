<?php

namespace App\Support\Jobs;

use App\Models\Estimate;
use App\Models\Organization;
use App\Models\WorkizRecord;
use App\Support\Settings\SettingsResolver;

/**
 * How long a job has waited on its estimate, and what has been paid: shared by
 * the daily `job.estimate_aging` trigger and the Project Management tab, so
 * the two never disagree (Jose's PM rules, 2026-10-21).
 */
final class EstimateAging
{
    /**
     * The days the rule template waits before cancelling. The rule holds its own
     * threshold; this is only for saying "cancels on …" on screen
     * (jobs.estimate_deadline_days).
     * ponytail: a second copy of the rule's number; read it from the rule when templates let it vary.
     */
    public static function days(Organization $organization): int
    {
        return (int) app(SettingsResolver::class)->get('jobs.estimate_deadline_days', $organization);
    }

    /**
     * The LATEST estimate date per job: EzBizi's own (issued_on, else the day it
     * was made) or Workiz's estimate records, whichever is later.
     *
     * @param  list<string>  $jobIds
     * @return array<string, string> job id => Y-m-d
     */
    public static function latestEstimateDates(array $jobIds): array
    {
        $ours = Estimate::query()->whereIn('job_id', $jobIds)
            ->selectRaw('job_id, MAX(COALESCE(issued_on, DATE(created_at))) AS d')->groupBy('job_id')->pluck('d', 'job_id');
        $theirs = WorkizRecord::query()->whereIn('job_id', $jobIds)->where('type', 'estimate')
            ->selectRaw('job_id, MAX(DATE(starts_at)) AS d')->groupBy('job_id')->pluck('d', 'job_id');

        $out = [];
        foreach ($jobIds as $id) {
            $dates = array_filter([$ours[$id] ?? null, $theirs[$id] ?? null]);
            if ($dates !== []) {
                $out[$id] = (string) max($dates);
            }
        }

        return $out;
    }
}
