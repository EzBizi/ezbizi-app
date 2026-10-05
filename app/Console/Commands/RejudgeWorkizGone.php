<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Judge again, by SyncWorkizJobs::named() as it stands, the EzBizi jobs a
 * Workiz deletion kept because "something in EzBizi names it". Until that rule
 * stopped counting a lead's phone match and an automation event (2026-10-05),
 * most of the jobs A-1 moved from Nationwide to Massachusetts stayed in EzBizi
 * beside their Massachusetts copies.
 *
 * Their mirror rows are gone, and with them what the projection wrote, so
 * "untouched" is read off the job itself: nothing has changed it since it was
 * judged.
 */
class RejudgeWorkizGone extends Command
{
    protected $signature = 'ezbizi:workiz-rejudge-gone {--dry-run : Report what would go and change nothing}';

    protected $description = 'Judge again the EzBizi jobs a Workiz deletion kept, by the rule as it stands';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $kept = DB::table('audit_events')->where('action', 'workiz.job_gone')
            ->where('metadata->ezbizi_job', 'kept: something in EzBizi names it')
            ->get(['organization_id', 'target_id', 'occurred_at', 'metadata'])
            ->groupBy('organization_id');
        $gone = 0;

        foreach ($kept as $organizationId => $rows) {
            $tenant->run(Organization::query()->withoutGlobalScopes()->findOrFail($organizationId), function () use ($rows, $audit, &$gone) {
                $named = SyncWorkizJobs::named($rows->pluck('target_id')->all());

                foreach ($rows as $row) {
                    $job = Job::query()->find($row->target_id);

                    // Whole seconds: the audit row may not keep the job's fraction.
                    if ($job === null || isset($named[$job->getKey()]) || $job->updated_at->getTimestamp() > Carbon::parse($row->occurred_at)->getTimestamp()) {
                        continue;
                    }

                    $gone++;

                    if ($this->option('dry-run')) {
                        continue;
                    }

                    DB::transaction(function () use ($job, $row, $audit) {
                        $released = SyncWorkizJobs::release($job);
                        $audit->log('workiz.job_gone', $job, metadata: ['ezbizi_job' => 'deleted', 'judged_again' => true, 'leads_released' => $released]
                            + json_decode($row->metadata, true));
                    });
                }
            });
        }

        $this->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$gone.' kept job(s).');

        return self::SUCCESS;
    }
}
