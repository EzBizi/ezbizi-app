<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Copies Workiz's JobType, SubStatus and Tags onto the EzBizi job, once, so the
 * native fields start with A-1's history (2026-10-20). Only fields nobody has
 * set: a person's word, and an emptied tag list ([]), survive a re-run.
 */
class BackfillWorkizJobLabels extends Command
{
    protected $signature = 'jobs:backfill-workiz-labels {--dry-run : Count, write nothing}';

    protected $description = "Fill jobs' type, sub-status and tags from the Workiz mirror where unset";

    public function handle(TenantContext $tenant): int
    {
        $filled = 0;

        foreach (Organization::query()->get() as $organization) {
            $tenant->run($organization, function () use (&$filled) {
                WorkizJob::query()->whereNotNull('job_id')->chunkById(500, function ($rows) use (&$filled) {
                    $jobs = Job::query()->whereIn('id', $rows->pluck('job_id'))->get()->keyBy('id');

                    foreach ($rows as $row) {
                        $job = $jobs[$row->job_id] ?? null;
                        $p = is_array($row->payload) ? $row->payload : [];

                        if (! $job instanceof Job) {
                            continue;
                        }

                        if ($job->job_type === null && is_string($p['JobType'] ?? null)) {
                            $job->job_type = $p['JobType'];
                        }
                        if ($job->sub_status === null && is_string($p['SubStatus'] ?? null)) {
                            $job->sub_status = $p['SubStatus'];
                        }
                        if ($job->getRawOriginal('tags') === null && is_array($p['Tags'] ?? null)) {
                            $job->tags = $p['Tags'];
                        }

                        if ($job->isDirty()) {
                            $filled++;
                            if (! $this->option('dry-run')) {
                                $job->saveQuietly();
                            }
                        }
                    }
                });
            });
        }

        $this->info(($this->option('dry-run') ? 'Would fill ' : 'Filled ').$filled.' jobs.');

        return self::SUCCESS;
    }
}
