<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every company that has no service areas an "All areas" catch-all per
 * branch, with the source company's weekly windows copied in (Jose via Bar,
 * 2026-10-22: "we are just copying the timeslots"). A copy, not a link: each
 * company edits its own afterwards.
 *
 * The pattern is the source's typical week: a (weekday, window) every second
 * area of the source that has a week works, at the median capacity. Sub-companies are skipped
 * when their parent has areas, because they book on the parent's availability.
 * Upcoming jobs of a seeded branch with no area are placed in the new area so
 * they count against it.
 */
class SchedulingSeedCompanyAvailability extends Command
{
    /** The seeded catch-all's name; a branch's first real area takes it over. */
    public const NAME = 'All areas';

    protected $signature = 'scheduling:seed-company-availability
        {--from= : The source company name (e.g. "A-1 Chimney")}
        {--organization= : The organization name, when the company name is in several}
        {--dry-run : Say what would be created and change nothing}';

    protected $description = 'Seed catch-all availability for companies with no service areas';

    public function handle(TenantContext $tenant): int
    {
        $sources = Company::withoutGlobalScopes()->where('name', (string) $this->option('from'))
            ->when($this->option('organization'), fn ($q, $o) => $q->whereIn('organization_id', Organization::where('name', $o)->select('id')))
            ->get();
        if ($sources->count() !== 1) {
            $this->error("Found {$sources->count()} companies called \"{$this->option('from')}\"; name exactly one (add --organization).");

            return self::FAILURE;
        }
        $source = $sources->first();
        $tenant->set(Organization::findOrFail($source->organization_id));

        $pattern = $this->pattern($source);
        if ($pattern === []) {
            $this->error("{$source->name} has no weekly windows to copy.");

            return self::FAILURE;
        }
        $this->line('Pattern: '.collect($pattern)->map(fn ($p) => "d{$p['weekday']} {$p['start_time']}-{$p['end_time']}×{$p['max_jobs']}")->implode(', '));

        $hasAreas = fn (array $companyIds) => ServiceArea::query()
            ->whereIn('franchise_id', Franchise::query()->whereIn('company_id', $companyIds)->pluck('id'))->exists();

        foreach (Company::query()->orderBy('name')->get() as $company) {
            if ($hasAreas([$company->id]) || ($company->parent_company_id && $hasAreas([$company->parent_company_id]))) {
                continue;
            }
            foreach (Franchise::query()->where('company_id', $company->id)->get() as $franchise) {
                $this->line("{$company->name} › {$franchise->name}: All areas, ".count($pattern).' windows');
                if ($this->option('dry-run')) {
                    continue;
                }
                DB::transaction(function () use ($franchise, $pattern) {
                    $area = ServiceArea::create(['franchise_id' => $franchise->id, 'name' => self::NAME, 'covers_all_zips' => true]);
                    foreach ($pattern as $p) {
                        ServiceAreaSlot::create($p + ['service_area_id' => $area->id]);
                    }
                    $placed = Job::query()->where('franchise_id', $franchise->id)->whereNull('service_area_id')
                        ->where('scheduled_for', '>=', now()->startOfDay())->update(['service_area_id' => $area->id]);
                    $this->line("  {$placed} upcoming jobs placed in it");
                });
            }
        }

        return self::SUCCESS;
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string, max_jobs: int}> */
    private function pattern(Company $source): array
    {
        $areas = ServiceArea::query()->whereIn('franchise_id', Franchise::query()->where('company_id', $source->id)->pluck('id'))->pluck('id');
        $rows = ServiceAreaSlot::query()->whereIn('service_area_id', $areas)->get(['service_area_id', 'weekday', 'start_time', 'end_time', 'max_jobs']);

        return $rows->groupBy(fn ($r) => $r->weekday.'|'.$r->start_time.'|'.$r->end_time)
            // Of the areas that have a week at all: on A-1, 22 of 47 have none.
            ->filter(fn ($g) => $g->count() * 2 >= $rows->pluck('service_area_id')->unique()->count())
            ->map(fn ($g) => [
                'weekday' => (int) $g->first()->weekday,
                'start_time' => (string) $g->first()->start_time,
                'end_time' => (string) $g->first()->end_time,
                'max_jobs' => (int) max(1, round($g->pluck('max_jobs')->median())),
            ])->sortBy(fn ($p) => $p['weekday'].$p['start_time'])->values()->all();
    }
}
