<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A mirrored job's real birth (Jose, 2026-10-27: EzBizi the master): its
 * created_at becomes Workiz's CreatedDate (the branch's wall clock), not the
 * moment the sync copied it in, and who opened it is kept as a name and, when
 * that Workiz member is linked, the EzBizi user. Fills only what is unset or
 * still the import time; a re-run changes nothing.
 */
class BackfillWorkizJobCreated extends Command
{
    protected $signature = 'jobs:backfill-workiz-created {--dry-run : Count, write nothing}';

    protected $description = "Set mirrored jobs' created date and creator from Workiz";

    public function handle(TenantContext $tenant): int
    {
        $changed = 0;
        foreach (Organization::query()->get() as $org) {
            $tenant->run($org, function () use (&$changed) {
                $users = WorkizTeamMember::query()->whereNotNull('user_id')->get(['franchise_id', 'name', 'user_id'])
                    ->mapWithKeys(fn ($m) => [$m->franchise_id.'|'.mb_strtolower(trim($m->name)) => $m->user_id]);

                WorkizJob::query()->whereNotNull('job_id')->with('franchise:id,timezone')->chunkById(1000, function ($rows) use ($users, &$changed) {
                    $jobs = DB::table('jobs')->whereIn('id', $rows->pluck('job_id'))->get(['id', 'created_at', 'created_by_user_id', 'created_by_label'])->keyBy('id');
                    foreach ($rows as $row) {
                        $job = $jobs[$row->job_id] ?? null;
                        $p = is_array($row->payload) ? $row->payload : [];
                        if ($job === null) {
                            continue;
                        }
                        $set = [];
                        if (is_string($p['CreatedDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $p['CreatedDate'])) {
                            $born = Carbon::createFromFormat('Y-m-d H:i:s', $p['CreatedDate'], $row->franchise?->timezone ?: 'America/New_York')->utc()->format('Y-m-d H:i:s');
                            if ($born !== $job->created_at) {
                                $set['created_at'] = $born;
                            }
                        }
                        $by = trim((string) ($p['CreatedBy'] ?? ''));
                        if ($by !== '' && $job->created_by_label === null) {
                            $set['created_by_label'] = $by;
                        }
                        if ($by !== '' && $job->created_by_user_id === null && isset($users[$row->franchise_id.'|'.mb_strtolower($by)])) {
                            $set['created_by_user_id'] = $users[$row->franchise_id.'|'.mb_strtolower($by)];
                        }
                        if ($set !== []) {
                            $changed++;
                            if (! $this->option('dry-run')) {
                                DB::table('jobs')->where('id', $job->id)->update($set);
                            }
                        }
                    }
                });
            });
        }
        $this->info(($this->option('dry-run') ? 'Would set ' : 'Set ').$changed.' jobs.');

        return self::SUCCESS;
    }
}
