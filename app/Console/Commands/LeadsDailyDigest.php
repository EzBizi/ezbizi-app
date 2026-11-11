<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\LeadDashboardController;
use App\Models\Lead;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every morning (12:00 UTC, 8 AM Eastern): raise `lead.daily_digest` with
 * yesterday in numbers, in the organization's own day, so a rule can send it
 * on WhatsApp or by email - A1 Leads' daily digest (Jose, 2026-09-30). The
 * figures are the Leads dashboard's (LeadDashboardController::measure), so the
 * message and the screen cannot disagree. Nothing is raised for a day with no
 * leads.
 */
class LeadsDailyDigest extends Command
{
    protected $signature = 'ezbizi:leads-daily-digest';

    protected $description = 'Raise lead.daily_digest with yesterday in numbers';

    public function handle(TenantContext $tenant, SettingsResolver $settings, EventEmitter $events): int
    {
        $raised = 0;

        foreach (Organization::query()->withoutGlobalScopes()->get() as $organization) {
            $tenant->run($organization, function () use ($organization, $settings, $events, &$raised) {
                $zone = (string) $settings->get('timezone', $organization);
                $day = now($zone)->subDay()->startOfDay();
                $leads = fn () => Lead::query()->where('leads.is_test', false)->whereNull('leads.hidden_at');

                $m = LeadDashboardController::measure($leads, $day, $day, $zone);
                $t = $m['totals'];
                if ($t['leads'] === 0) {
                    return;
                }

                $bySource = [];
                foreach ($m['per_day'][0]['sources'] ?? [] as $s) {
                    $word = self::word($s['source'], $s['lead_type']);
                    $bySource[$word] = ($bySource[$word] ?? 0) + $s['count'];
                }
                arsort($bySource);

                $byCompany = DB::table('leads')->join('companies', 'companies.id', '=', 'leads.company_id')
                    ->where('leads.organization_id', $organization->getKey())->where('leads.is_test', false)
                    ->whereBetween('leads.received_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])
                    ->groupBy('companies.name')->orderByDesc(DB::raw('COUNT(*)'))
                    ->pluck(DB::raw('COUNT(*)'), 'companies.name')->map(fn ($n) => (int) $n)->all();

                $answeredPct = $t['answerable'] > 0 ? (int) round(100 * $t['answered'] / $t['answerable']) : null;
                $median = $t['first_reply_median_seconds'] === null ? null : (int) round($t['first_reply_median_seconds'] / 60);

                // One line: WhatsApp refuses a template parameter with a line
                // break in it.
                $summary = $day->format('D M j').': '.$t['leads'].' leads ('
                    .implode(', ', array_map(fn ($n, $w) => "$n $w", $bySource, array_keys($bySource))).')'
                    .($answeredPct === null ? '' : " - {$answeredPct}% of texts answered")
                    .($median === null ? '' : ", first reply in {$median} min (median)")
                    ." - {$t['booked']} booked"
                    .(count($byCompany) > 1 ? ' - '.implode(', ', array_map(fn ($n, $c) => "$c $n", $byCompany, array_keys($byCompany))) : '')
                    .'.';

                // The same, a line each, for a message that may break lines (sent as a report, 2026-11-11).
                $lines = implode("\n", array_filter([
                    '*'.$day->format('D M j').': '.$t['leads'].' leads*',
                    ...array_map(fn ($n, $w) => "$w: $n", $bySource, array_keys($bySource)),
                    $answeredPct === null ? null : "Texts answered: {$answeredPct}%",
                    $median === null ? null : "First reply: {$median} min (median)",
                    "*Booked: {$t['booked']}*",
                    ...(count($byCompany) > 1 ? array_map(fn ($n, $c) => "$c: $n", $byCompany, array_keys($byCompany)) : []),
                ], fn ($l) => $l !== null));

                $events->emit('lead.daily_digest', $organization, [
                    'after' => [
                        'date' => $day->toDateString(),
                        'summary' => $summary,
                        'summary_lines' => $lines,
                        'leads' => $t['leads'],
                        'answerable' => $t['answerable'],
                        'answered' => $t['answered'],
                        'answered_percent' => $answeredPct,
                        'first_reply_median_minutes' => $median,
                        'within_10_min' => $t['within_10_min'],
                        'booked' => $t['booked'],
                        'automatic_replies' => $m['automatic']['replies'],
                        'by_source' => $bySource,
                        'by_company' => $byCompany,
                    ],
                ]);
                $raised++;
            });
        }

        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }

    /** The words on the Leads list's source tags. */
    private static function word(string $source, ?string $type): string
    {
        return match (true) {
            $source === Lead::SOURCE_LOCAL_SERVICES && $type === 'PHONE_CALL' => 'LS calls',
            $source === Lead::SOURCE_LOCAL_SERVICES && $type === 'MESSAGE' => 'LS texts',
            $source === Lead::SOURCE_LOCAL_SERVICES => 'LS bookings',
            $source === Lead::SOURCE_WEBSITE_FORM => 'forms',
            $source === Lead::SOURCE_WEBSITE_BOOKING => 'online bookings',
            $source === Lead::SOURCE_WEBSITE_CHAT => 'chats',
            $source === Lead::SOURCE_FACEBOOK => 'Facebook',
            default => ucfirst($source),
        };
    }
}
