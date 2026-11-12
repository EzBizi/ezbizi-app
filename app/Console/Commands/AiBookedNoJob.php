<?php

namespace App\Console\Commands;

use App\Models\CallReportConversion;
use App\Models\CallReportDispatcherTag;
use App\Models\CallReportLabel;
use App\Support\Automations\EventEmitter;
use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Reports\ReportView;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Yesterday's calls Grok read as booked that have no Workiz job (Jose, via
 * Grok AI, 2026-10-13: "Yes" to a daily list for a manager to follow up).
 * Raises reports.ai_booked_no_job once, with the list, when there are any;
 * the same booked test as the Performance report (CallReconciliation).
 */
class AiBookedNoJob extends Command
{
    protected $signature = 'ezbizi:ai-booked-no-job {--day= : Y-m-d, default yesterday} {--organization=}';

    protected $description = 'Raise the calls AI says were booked that have no Workiz job';

    public function handle(RingCentralCallLog $calls, WorkizJobs $workiz, CallReconciliation $reconcile, TenantContext $tenant, EventEmitter $events): int
    {
        $organization = TranscribeReportCalls::reportOrganization($this->option('organization'));
        if ($organization === null || ! $calls->configured()) {
            return self::SUCCESS;
        }

        $zone = (string) config('app.timezone');
        $day = CarbonImmutable::parse($this->option('day') ?? 'yesterday', $zone)->startOfDay();

        $found = $tenant->run($organization, function () use ($calls, $workiz, $reconcile, $day) {
            $report = $reconcile->forRange(
                $calls->forPeriod($day, $day->endOfDay()),
                $workiz->storedAll($day->subDays(ReportView::lookbackDays())->toDateString())['jobs'],
                $day->toDateString(),
                $day->toDateString(),
                CallReportConversion::query()->pluck('call_id')->all(),
                CallReportDispatcherTag::query()->pluck('tag', 'person')->all(),
            );

            $booked = CallReportLabel::query()->where('provider', 'grok')->where('label', 'booked')
                ->whereIn('call_id', array_column($report['calls'] ?? [], 'id'))->pluck('call_id')->flip();

            return array_values(array_filter($report['calls'] ?? [], fn ($c) => isset($booked[$c['id']]) && ! $c['booked'] && ($c['existing_job'] ?? null) === null && ($c['mark'] ?? null) === null));
        });

        $this->info('AI booked, no job: '.count($found));

        if ($found === []) {
            return self::SUCCESS;
        }

        $lines = array_map(fn ($c) => '- '.CarbonImmutable::parse($c['started_at'])->setTimezone($zone)->format('g:ia')
            .' · '.($c['queue'] ?? 'unknown line')
            .' · '.($c['from'] ?? 'unknown number')
            .' · answered by '.($c['answered_by'] ?? 'nobody'), $found);

        $tenant->run($organization, fn () => $events->emit('reports.ai_booked_no_job', null, [
            'count' => count($found),
            'day' => $day->toDateString(),
            'summary' => "Calls on {$day->format('M j')} that AI read as booked, with no Workiz job:\n\n".implode("\n", $lines),
        ]));

        return self::SUCCESS;
    }
}
