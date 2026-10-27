<?php

namespace App\Support\Scheduling;

use App\Models\Job;
use App\Models\JobTechnician;
use App\Models\SchedulingTechnician;
use App\Models\WorkizTeamMember;
use Illuminate\Support\Facades\DB;

/**
 * Who is on a job, in EzBizi's own tables (Jose, 2026-10-27: EzBizi is the
 * master). A person's assignment (`ezbizi`) is never overwritten by an import;
 * an imported one (`workiz`) is replaced by the next import until a person
 * touches the job.
 */
final class JobTechnicians
{
    public static function assign(Job $job, SchedulingTechnician $tech): void
    {
        DB::transaction(function () use ($job, $tech) {
            // Touching the job makes all of its team a person's decision.
            JobTechnician::query()->where('job_id', $job->id)->update(['source' => JobTechnician::EZBIZI]);
            JobTechnician::query()->updateOrInsert(['job_id' => $job->id, 'technician_id' => $tech->id],
                ['source' => JobTechnician::EZBIZI, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public static function unassign(Job $job, SchedulingTechnician $tech): void
    {
        DB::transaction(function () use ($job, $tech) {
            JobTechnician::query()->where('job_id', $job->id)->update(['source' => JobTechnician::EZBIZI]);
            JobTechnician::query()->where('job_id', $job->id)->where('technician_id', $tech->id)->delete();
        });
    }

    /**
     * An import's team for a job: Workiz `Team` entries ({id, Name}). Leaves
     * the job alone once a person has assigned anybody on it.
     *
     * @param  list<array<string, mixed>>  $team
     */
    public static function fromWorkiz(Job $job, array $team): void
    {
        if (JobTechnician::query()->where('job_id', $job->id)->where('source', JobTechnician::EZBIZI)->exists()) {
            return;
        }
        $remote = collect($team)->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
        $members = WorkizTeamMember::query()->where('franchise_id', $job->franchise_id)->whereIn('remote_id', $remote)->get(['id', 'payload']);
        // A v1 row retired by v2 names its successor: that is the same person.
        $successors = $members->map(fn ($m) => $m->payload['succeeded_by'] ?? null)->filter()->values()->all();
        $ids = $members->reject(fn ($m) => isset($m->payload['succeeded_by']))->pluck('id')
            ->merge(WorkizTeamMember::query()->where('franchise_id', $job->franchise_id)->whereIn('remote_id', $successors)->pluck('id'));
        $techs = SchedulingTechnician::query()->whereIn('workiz_member_id', $ids)->pluck('id')->unique();

        DB::transaction(function () use ($job, $techs) {
            JobTechnician::query()->where('job_id', $job->id)->where('source', JobTechnician::WORKIZ)->delete();
            foreach ($techs as $id) {
                JobTechnician::query()->insert(['job_id' => $job->id, 'technician_id' => $id, 'source' => JobTechnician::WORKIZ, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
