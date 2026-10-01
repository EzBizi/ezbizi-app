<?php

namespace App\Console\Commands;

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
    public const DAYS = 7;

    /** The trigger the warning email's automation listens on. */
    public const TRIGGER = 'scheduling.overbooking_found';

    protected $signature = 'scheduling:warn-overbooking';

    protected $description = 'Raise the overbooking trigger for areas overbooked or fully booked in the next 7 days';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $ids = ServiceArea::withoutGlobalScopes()->distinct()->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $ids)->get() as $organization) {
            $tenant->run($organization, function () use ($organization, $events) {
                $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $organization));
                $areas = ServiceArea::query()->get();
                $check = app(Overbooking::class);

                $over = $check->overbooked($areas, $from, self::DAYS);
                $full = $check->fullyBooked($areas, $from, self::DAYS);

                if ($over === [] && $full === []) {
                    return;
                }

                // Filed against the organization: the warning is about all of
                // its areas, and an organization carries its own audience.
                $events->emit(self::TRIGGER, $organization, ['after' => [
                    'summary' => self::summary($over, $full),
                    'overbooked_days' => count($over),
                    'fully_booked_areas' => count($full),
                    'from' => $from->toDateString(),
                    'to' => $from->addDays(self::DAYS - 1)->toDateString(),
                ]]);

                $this->line("{$organization->name}: ".count($over).' overbooked, '.count($full).' fully booked');
            });
        }

        return self::SUCCESS;
    }

    /**
     * The warning as Markdown, days under headings (UI queue 39: Jose wanted
     * it "more formatted ... not just text"). The trigger declares `summary`
     * EzBizi-composed, so an email renders this as structure; the email's text
     * part takes the marks back out. Area and franchise names are escaped: they
     * are typed by somebody, and a name with a * in it is not emphasis.
     *
     * @param  array<int, array<string, mixed>>  $over
     * @param  array<int, array<string, mixed>>  $full
     */
    public static function summary(array $over, array $full): string
    {
        $day = fn (string $d) => CarbonImmutable::parse($d)->format('D M j');
        $name = fn (?string $s) => ActionRunner::escapeMarkdown((string) $s);
        $lines = [];

        if ($over !== []) {
            $lines[] = '## OVERBOOKED';
            foreach (collect($over)->groupBy('date') as $date => $rows) {
                $lines[] = '';
                $lines[] = "**{$day((string) $date)}**";
                $lines[] = '';
                foreach ($rows as $r) {
                    $who = $r['level'] === 'franchise'
                        ? "{$name($r['name'])} (whole franchise)"
                        : ($r['franchise_name'] !== null ? "{$name($r['franchise_name'])} › {$name($r['name'])}" : $name($r['name']));
                    $what = $r['closed']
                        ? "{$r['booked']} booked on a closed day"
                        : "{$r['booked']} booked of {$r['capacity']} ({$r['over']} over)";
                    $lines[] = "- {$who}: {$what}";
                }
            }
        }

        if ($full !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = '## FULLY BOOKED, no spot left in the next '.self::DAYS.' days';
            $lines[] = '';
            foreach ($full as $r) {
                $who = $r['franchise_name'] !== null ? "{$name($r['franchise_name'])} › {$name($r['name'])}" : $name($r['name']);
                $next = $r['next_opening'] !== null ? 'next opening '.$day($r['next_opening']) : 'no opening found';
                $lines[] = "- {$who} ({$next})";
            }
        }

        $lines[] = '';
        $lines[] = 'See Scheduling › Overbooking in EzBizi for the details.';

        return implode("\n", $lines);
    }
}
