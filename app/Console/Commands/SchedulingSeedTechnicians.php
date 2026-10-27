<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Organization;
use App\Models\SchedulingTechnician;
use App\Models\ServiceArea;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Scheduling\JobTechnicians;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Seeds EzBizi's technician roster from the Workiz mirror, once (Jose,
 * 2026-10-27: EzBizi is the master). A member already seeded is left as it is,
 * so re-running only adds people Workiz gained since; EzBizi owns every field
 * after the first copy. Then each EzBizi job's team is filled from its Workiz
 * row, which leaves any job a person has already assigned alone.
 */
class SchedulingSeedTechnicians extends Command
{
    protected $signature = 'scheduling:seed-technicians {--dry-run : Count what would be created}';

    protected $description = 'Seed the native technician roster and job teams from the Workiz mirror';

    public function handle(TenantContext $tenant): int
    {
        $orgs = WorkizTeamMember::withoutGlobalScopes()->distinct()->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $orgs)->get() as $organization) {
            $tenant->run($organization, function () use ($organization) {
                $seeded = WorkizTeamMember::query()->whereIn('id', SchedulingTechnician::query()->whereNotNull('workiz_member_id')->select('workiz_member_id'))->pluck('id')->flip();
                $new = WorkizTeamMember::query()->get()->reject(fn ($m) => isset($seeded[$m->id])
                    // A v1 row retired by v2 is the same person as its successor.
                    || isset($m->payload['succeeded_by']));
                $areas = ServiceArea::query()->get(['id', 'franchise_id', 'name'])
                    ->groupBy(fn ($a) => $a->franchise_id.'|'.mb_strtolower(trim($a->name)));

                $this->line("{$organization->name}: {$new->count()} technicians to add");
                if ($this->option('dry-run')) {
                    return;
                }

                foreach ($new as $m) {
                    $tech = SchedulingTechnician::create([
                        'franchise_id' => $m->franchise_id, 'user_id' => $m->user_id, 'name' => trim((string) $m->name) ?: 'Unnamed',
                        'email' => $m->email, 'phone' => $m->payload['phone'] ?? $m->payload['Phone'] ?? null,
                        'is_field_tech' => (bool) $m->is_field_tech, 'is_active' => (bool) $m->is_active, 'workiz_member_id' => $m->id,
                    ]);
                    $ids = collect($m->service_areas ?? [])->flatMap(fn ($n) => $areas->get($m->franchise_id.'|'.mb_strtolower(trim((string) $n)), collect())->pluck('id'));
                    $tech->areas()->sync($ids->unique()->all());
                }

                $teams = 0;
                WorkizJob::query()->whereNotNull('job_id')->select(['id', 'job_id', 'payload'])->chunkById(500, function ($rows) use (&$teams) {
                    $jobs = Job::query()->whereIn('id', $rows->pluck('job_id'))->get(['id', 'franchise_id'])->keyBy('id');
                    foreach ($rows as $row) {
                        $team = $row->payload['Team'] ?? $row->payload['team'] ?? [];
                        if (is_array($team) && $team !== [] && isset($jobs[$row->job_id])) {
                            JobTechnicians::fromWorkiz($jobs[$row->job_id], $team);
                            $teams++;
                        }
                    }
                });
                $this->line("  {$teams} job teams filled");
            });
        }

        return self::SUCCESS;
    }
}
