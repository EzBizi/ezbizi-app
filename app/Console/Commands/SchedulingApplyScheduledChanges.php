<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ServiceAreaScheduledSlot;
use App\Models\ServiceAreaSlot;
use App\Support\Scheduling\Openings;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * On the day a scheduled weekly pattern starts, make it the area's pattern
 * (Eve, 2026-09-29: "change automatically starting from October 30").
 *
 * Openings already reads a started version for availability; this folds it
 * into `service_area_slots` so the Overview, the pattern check and every other
 * reader of the weekly pattern agree from that day on, then removes it. Of
 * several versions that have started, the latest wins and the older ones are
 * dropped with it. Idempotent: run hourly, it does nothing until a date turns.
 */
class SchedulingApplyScheduledChanges extends Command
{
    protected $signature = 'scheduling:apply-scheduled-changes';

    protected $description = 'Fold scheduled weekly patterns into the area pattern on the day they start';

    public function handle(TenantContext $tenant): int
    {
        $ids = ServiceAreaScheduledSlot::withoutGlobalScopes()->distinct()->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $ids)->get() as $organization) {
            $tenant->run($organization, function () use ($organization) {
                $today = Openings::tomorrow((string) app(SettingsResolver::class)
                    ->get('timezone', $organization))->subDay()->toDateString();

                $due = ServiceAreaScheduledSlot::query()
                    ->where('starts_on', '<=', $today)
                    ->get()
                    ->groupBy('service_area_id');

                foreach ($due as $areaId => $rows) {
                    $latest = $rows->max(fn ($r) => $r->starts_on->toDateString());
                    $week = $rows->filter(fn ($r) => $r->starts_on->toDateString() === $latest);

                    DB::transaction(function () use ($areaId, $week, $today) {
                        ServiceAreaSlot::where('service_area_id', $areaId)->delete();

                        foreach ($week as $r) {
                            ServiceAreaSlot::create([
                                'service_area_id' => $areaId,
                                'weekday' => $r->weekday,
                                'start_time' => $r->start_time,
                                'end_time' => $r->end_time,
                                'max_jobs' => $r->max_jobs,
                            ]);
                        }

                        ServiceAreaScheduledSlot::where('service_area_id', $areaId)
                            ->where('starts_on', '<=', $today)
                            ->delete();
                    });

                    $this->line("{$organization->name}: area {$areaId} now runs the week from {$latest}");
                }
            });
        }

        return self::SUCCESS;
    }
}
