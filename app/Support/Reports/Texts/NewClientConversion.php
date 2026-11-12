<?php

namespace App\Support\Reports\Texts;

use App\Models\CallReportConversion;
use App\Models\CallReportDispatcherTag;
use App\Models\Organization;
use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Reports\ReportView;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * "Daily Conversion Updates (new clients only)" for the staff WhatsApp bot's
 * Office menu (Jose approved, 2026-10-13): one day's new-client calls,
 * answered, booked and the conversion rate, per dispatcher, as WhatsApp text
 * in the house style. The same booked test as the Performance report; no
 * person to scope by, so every Workiz account of the organization.
 */
final class NewClientConversion
{
    public static function line(Organization $organization, CarbonImmutable $day): string
    {
        $day = $day->startOfDay();

        $calls = app(TenantContext::class)->run($organization, function () use ($day) {
            $report = app(CallReconciliation::class)->forRange(
                app(RingCentralCallLog::class)->forPeriod($day, $day->endOfDay()),
                app(WorkizJobs::class)->storedAll($day->subDays(ReportView::lookbackDays())->toDateString())['jobs'],
                $day->toDateString(),
                $day->toDateString(),
                CallReportConversion::query()->pluck('call_id')->all(),
                CallReportDispatcherTag::query()->pluck('tag', 'person')->all(),
            );

            return array_values(array_filter($report['calls'] ?? [], fn ($c) => RingCentralCallLog::clientOf($c['queue'] ?? null) === 'new' && ($c['mark'] ?? null) === null
                && ($c['direction'] ?? 'Inbound') === 'Inbound'));
        });

        $answered = array_values(array_filter($calls, fn ($c) => ($c['answered_by'] ?? null) !== null));
        // A caller who already had a job is not a booking to win or lose.
        $counted = array_values(array_filter($answered, fn ($c) => ($c['existing_job'] ?? null) === null));
        $booked = count(array_filter($counted, fn ($c) => $c['booked']));
        $rate = fn (int $won, int $of) => $of > 0 ? round($won / $of * 100).'%' : '—';

        $lines = [
            '📞 *New-client conversion · '.$day->format('D, M j').'*',
            '',
            '📲 '.count($calls).' new-client calls',
            '🙋 '.count($answered).' answered',
            '✅ *'.$booked.'* booked',
            '📈 Conversion: *'.$rate($booked, count($counted)).'*',
        ];

        if (($existing = count($answered) - count($counted)) > 0) {
            $lines[] = '🔁 '.$existing.' already had a job (not counted)';
        }

        $people = [];
        foreach ($counted as $c) {
            $people[$c['answered_by']]['calls'] = ($people[$c['answered_by']]['calls'] ?? 0) + 1;
            $people[$c['answered_by']]['booked'] = ($people[$c['answered_by']]['booked'] ?? 0) + ($c['booked'] ? 1 : 0);
        }
        uasort($people, fn ($a, $b) => [$b['booked'], $b['calls']] <=> [$a['booked'], $a['calls']]);

        if ($people !== []) {
            $lines[] = '';
            $lines[] = '👥 *By dispatcher*';
            foreach ($people as $name => $p) {
                $lines[] = "• {$name}: {$p['booked']}/{$p['calls']} booked · *".$rate($p['booked'], $p['calls']).'*';
            }
        }

        return implode("\n", $lines);
    }
}
