<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ServiceArea;
use App\Support\Automations\ActionRunner;
use App\Support\Automations\EventEmitter;
use App\Support\Reports\LocationOrder;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\Overbooking;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Each morning, raise `scheduling.overbooking_found` for every organization
 * with a service area overbooked, or fully booked, in the next seven days
 * (Jose, 2026-09-29). Who is told, and how, is an automation on that trigger —
 * for A-1 an email to three addresses — so the recipients are changed in
 * Automations, not here.
 *
 * Nothing found, nothing raised: a quiet morning sends no email.
 */
class SchedulingWarnOverbooking extends Command
{
    /** The trigger the warning email's automation listens on. */
    public const TRIGGER = 'scheduling.overbooking_found';

    protected $signature = 'scheduling:warn-overbooking {--now : Ignore the hour setting (for a manual run)}';

    protected $description = 'Raise the overbooking trigger for areas with no spot left for the next 10 days or more';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $ids = ServiceArea::withoutGlobalScopes()->distinct()->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $ids)->get() as $organization) {
            $tenant->run($organization, function () use ($organization, $events) {
                $zone = (string) app(SettingsResolver::class)->get('timezone', $organization);
                if (! $this->option('now') && (int) CarbonImmutable::now($zone)->format('G') !== (int) app(SettingsResolver::class)->get('scheduling.overbooking_hour', $organization)) {
                    return;
                }
                $from = Openings::tomorrow($zone);
                $areas = ServiceArea::query()->get();
                $check = app(Overbooking::class);

                // Only the areas full for Overbooking::fullDays() or more
                // (Shoval, 2026-10-05: so ads there can be paused). Overbooked
                // days are Scheduling › Overbooking's and the dashboard's, not
                // the email's.
                $full = $check->fullyBooked($areas, $from, Overbooking::fullDays());

                if ($full === []) {
                    return;
                }

                // Filed against the organization: the warning is about all of
                // its areas, and an organization carries its own audience.
                $events->emit(self::TRIGGER, $organization, ['after' => [
                    'summary' => self::summary($full, $from->subDay()),
                    'fully_booked_areas' => count($full),
                    'from' => $from->toDateString(),
                    'to' => $from->addDays(Overbooking::fullDays() - 1)->toDateString(),
                ]]);

                $this->line("{$organization->name}: ".count($full).' fully booked for '.Overbooking::fullDays().'+ days');
            });
        }

        return self::SUCCESS;
    }

    /**
     * The warning as Markdown (UI queue 39: Jose wanted it "more formatted ...
     * not just text"): every area with no spot left for the next
     * Overbooking::fullDays() days or more, the one furthest out first, with
     * its next opening and how far away that is. The trigger declares
     * `summary` EzBizi-composed, so an email renders this as structure; the
     * email's text part takes the marks back out. Names are escaped: they are
     * typed by somebody, and a name with a * in it is not emphasis.
     *
     * @param  array<int, array<string, mixed>>  $full  Overbooking::fullyBooked() rows
     */
    public static function summary(array $full, CarbonImmutable $today): string
    {
        $name = fn (?string $s) => ActionRunner::escapeMarkdown((string) $s);
        $lines = ['## FULLY BOOKED for '.Overbooking::fullDays().' days or more', ''];

        // Branches in the business's location order (Shoval, 2026-11-09), the most booked first in each.
        $place = LocationOrder::places(collect($full)->pluck('franchise_id'));
        $rows = collect($full)->sortByDesc(fn ($r) => $r['next_opening'] ?? '9999-12-31')
            ->sortBy(fn ($r) => $place[(string) ($r['franchise_id'] ?? '')] ?? PHP_INT_MAX);
        foreach ($rows as $r) {
            $who = $r['franchise_name'] !== null ? "{$name($r['franchise_name'])} › {$name($r['name'])}" : $name($r['name']);
            if ($r['next_opening'] === null) {
                $lines[] = "- {$who}: no opening in the next year";

                continue;
            }
            // In today's zone: a date parsed in UTC against New York's midnight
            // reads a day short.
            $next = CarbonImmutable::parse($r['next_opening'], $today->getTimezone());
            $away = (int) $today->startOfDay()->diffInDays($next->startOfDay());
            $lines[] = "- {$who}: next opening {$next->format('D M j')} ({$away} days away)";
        }

        $lines[] = '';
        $lines[] = 'Consider pausing ads for these areas. See Scheduling › Overbooking in EzBizi.';

        return implode("\n", $lines);
    }
}
