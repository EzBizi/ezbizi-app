<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizRecord;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `job.estimate_aging`: once a day, each job in a status the organization says
 * waits on an estimate (jobs.estimate_aging_statuses), with how many days since
 * its LATEST estimate and what has been paid (Jose's Project Management rule 2,
 * 2026-10-21). The rule keeps the threshold.
 *
 * The estimate date is the latest of EzBizi's own (issued_on, else the day it
 * was made) and Workiz's estimate records. Paid is Workiz's succeeded charges
 * less refunds, in cents, until EzBizi records its own payments. A job with no
 * estimate is skipped; a job already announced today is not announced again.
 */
class EmitJobEstimateAging extends Command
{
    public const TRIGGER = 'job.estimate_aging';

    protected $signature = 'jobs:emit-estimate-aging';

    protected $description = "Raise job.estimate_aging for jobs waiting on an estimate";

    public function handle(TenantContext $tenant, SettingsResolver $settings, EventEmitter $events): int
    {
        $raised = 0;

        foreach (Organization::query()->get() as $organization) {
            $tenant->run($organization, function () use ($organization, $settings, $events, &$raised) {
                $statuses = (array) $settings->get('jobs.estimate_aging_statuses', $organization);
                if ($statuses === []) {
                    return;
                }

                Job::query()->whereIn('status', $statuses)->with('franchise:id,timezone')
                    ->chunkById(500, function ($jobs) use ($events, &$raised) {
                        $ids = $jobs->modelKeys();
                        $ours = Estimate::query()->whereIn('job_id', $ids)
                            ->selectRaw('job_id, MAX(COALESCE(issued_on, DATE(created_at))) AS d')->groupBy('job_id')->pluck('d', 'job_id');
                        $theirs = WorkizRecord::query()->whereIn('job_id', $ids)->where('type', 'estimate')
                            ->selectRaw('job_id, MAX(DATE(starts_at)) AS d')->groupBy('job_id')->pluck('d', 'job_id');
                        $paid = WorkizRecord::query()->whereIn('job_id', $ids)->where('type', 'payment')->where('status', 'succeeded')
                            ->selectRaw("job_id, SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = 'refund' THEN -amount ELSE amount END) AS paid")
                            ->groupBy('job_id')->pluck('paid', 'job_id');
                        $already = AutomationEvent::query()->where('event_key', self::TRIGGER)->whereIn('subject_id', $ids)
                            ->where('occurred_at', '>=', now()->startOfDay())->pluck('subject_id')->flip();

                        foreach ($jobs as $job) {
                            $date = max(array_filter([$ours[$job->id] ?? null, $theirs[$job->id] ?? null]) ?: [null]);
                            if ($date === null || $already->has($job->id)) {
                                continue;
                            }

                            $today = now($job->franchise?->timezone ?: 'UTC')->startOfDay();
                            $events->emit(self::TRIGGER, $job, ['after' => [
                                'status' => $job->status,
                                'days_since_estimate' => (int) Carbon::parse($date, $today->timezone)->diffInDays($today),
                                'estimate_date' => $date,
                                'paid_total' => (int) round(((float) ($paid[$job->id] ?? 0)) * 100),
                            ]]);
                            $raised++;
                        }
                    });
            });
        }

        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }
}
