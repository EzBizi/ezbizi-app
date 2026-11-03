<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Support\Automations\ActionRunner;
use App\Support\Automations\EventEmitter;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\Overbooking;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The day before, at the hour each organization chose, raise
 * `scheduling.area_underfilled` ("tomorrow under-filled") listing every area
 * with at least `scheduling.underfilled_min_empty` empty spots tomorrow, per
 * branch with totals and a link to Booked vs available (Shoval and Jose,
 * 2026-10-26). Who gets the email, and how, is an automation rule on it, so
 * recipients are a rule's, never this command's. Runs hourly; each
 * organization fires once a day, at its own hour.
 */
class SchedulingWarnUnderfilled extends Command
{
    public const TRIGGER = 'scheduling.area_underfilled';

    public const MIN_EMPTY = 'scheduling.underfilled_min_empty';

    public const HOUR = 'scheduling.underfilled_hour';

    /** A branch left out of the report (Shoval, 2026-11-03: "disable nationwide"). */
    public const INCLUDE = 'scheduling.underfilled_include';

    protected $signature = 'scheduling:warn-underfilled {--now : Ignore the hour setting (for a manual run)}';

    protected $description = 'Raise "tomorrow under-filled" for areas with empty spots tomorrow';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $ids = ServiceArea::withoutGlobalScopes()->distinct()->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $ids)->get() as $organization) {
            $tenant->run($organization, function () use ($organization, $events) {
                $settings = app(SettingsResolver::class);
                $zone = (string) $settings->get('timezone', $organization);
                if (! $this->option('now') && (int) CarbonImmutable::now($zone)->format('G') !== (int) $settings->get(self::HOUR, $organization)) {
                    return;
                }
                $date = Openings::tomorrow($zone);
                if (! Cache::add('underfilled:'.$organization->id.':'.$date->toDateString(), true, 86400) && ! $this->option('now')) {
                    return;
                }
                $min = (int) $settings->get(self::MIN_EMPTY, $organization);
                $under = collect(app(Overbooking::class)->fill(ServiceArea::query()->get(), $date))
                    ->filter(fn ($r) => $r['capacity'] > 0 && $r['gap'] >= $min)
                    ->filter(fn ($r) => (bool) $settings->get(self::INCLUDE, Franchise::query()->find($r['franchise_id'])))->values();
                if ($under->isEmpty()) {
                    return;
                }

                // One event per company, its subject one of the company's
                // branches, so a rule scoped to a company (or a company above
                // it) gets that company's areas only, never every company's.
                foreach ($under->groupBy('company_id') as $companyId => $rows) {
                    $events->emit(self::TRIGGER, Franchise::query()->find($rows->first()['franchise_id']), ['after' => [
                        'summary' => self::summary($rows->all(), $date),
                        'company_id' => $companyId,
                        'company_name' => $rows->first()['company_name'],
                        'underfilled_areas' => $rows->count(),
                        'open_spots' => $rows->sum('gap'),
                        'date' => $date->toDateString(),
                    ]]);
                    $this->line("{$organization->name} / {$rows->first()['company_name']}: {$rows->count()} areas with {$min}+ empty spots");
                }
            });
        }

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows Overbooking::fill() rows */
    public static function summary(array $rows, CarbonImmutable $date): string
    {
        $name = fn (?string $s) => ActionRunner::escapeMarkdown((string) $s);
        $company = collect($rows)->pluck('company_name')->filter()->unique()->implode(', ');
        $lines = ['## '.($company === '' ? '' : $name($company).': ').'empty spots tomorrow, '.$date->format('D M j'), ''];
        foreach (collect($rows)->groupBy('franchise_id') as $areas) {
            $branch = $areas->first()['franchise_name'];
            $lines[] = "### {$name($branch)}: {$areas->sum('gap')} empty ({$areas->sum('booked')} of {$areas->sum('capacity')} booked)";
            foreach ($areas as $r) {
                $lines[] = "- {$name($r['name'])}: {$r['booked']} booked / {$r['capacity']} available, {$r['gap']} empty";
            }
            $lines[] = '';
        }
        $lines[] = '**Total: '.collect($rows)->sum('gap').' empty spots.**';
        $lines[] = '';
        $lines[] = '[Open Booked vs available]('.rtrim((string) config('app.url'), '/').'/scheduling/overbooking?date='.$date->toDateString().')';

        return implode("\n", $lines);
    }
}
