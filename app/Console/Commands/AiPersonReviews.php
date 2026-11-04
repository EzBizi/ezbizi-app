<?php

namespace App\Console\Commands;

use App\Models\CallReportPersonReview;
use App\Models\Organization;
use App\Support\Ai\GrokCli;
use App\Support\Reports\ReportView;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Grok's daily and weekly read of each employee's scored calls, for the
 * Scoring tab's cards (Jose, 2026-10-13: "Daily and Weekly"). Reads only the
 * scores' checks and one-line notes - never transcripts - grouped by who
 * answered (call_report_transcripts.answered_by). Trend is arithmetic against
 * the previous period, not Grok's opinion.
 */
class AiPersonReviews extends Command
{
    protected $signature = 'ezbizi:ai-person-reviews {period : daily|weekly} {--date= : a day inside the period, default yesterday / last week}';

    protected $description = 'Have Grok write a daily or weekly coaching read per employee from their scored calls';

    public function handle(TenantContext $tenant): int
    {
        if (! config('experimental_reports.scoring') || ! GrokCli::configured()) {
            return self::SUCCESS;
        }

        $weekly = $this->argument('period') === 'weekly';
        $zone = \App\Support\Settings\OrgTimezone::of();
        $day = $this->option('date') ? CarbonImmutable::parse($this->option('date'), $zone)
            : CarbonImmutable::now($zone)->subDays($weekly ? 7 : 1);
        [$from, $to] = $weekly ? [$day->startOfWeek(), $day->endOfWeek()] : [$day->startOfDay(), $day->endOfDay()];
        [$prevFrom, $prevTo] = $weekly ? [$from->subWeek(), $to->subWeek()] : [$from->subDay(), $to->subDay()];

        foreach (Organization::query()->withoutGlobalScopes()->get() as $organization) {
            $people = $this->calls($organization->getKey(), $from, $to)->groupBy('answered_by')
                ->filter(fn ($calls) => $calls->count() >= ReportView::minCalls());
            if ($people->isEmpty()) {
                continue;
            }
            $previous = $this->calls($organization->getKey(), $prevFrom, $prevTo)->groupBy('answered_by')
                ->map(fn ($calls) => $calls->avg('score'));

            $names = $people->keys()->values();
            $answers = GrokCli::askMany($names->map(fn ($name) => self::prompt($weekly, $people[$name]))->all(), self::SCHEMA, 60);

            $tenant->run($organization, function () use ($names, $answers, $people, $previous, $organization, $weekly, $from, $to) {
                foreach ($names as $n => $name) {
                    if ($answers[$n] instanceof RuntimeException) {
                        $this->warn('Grok: '.$answers[$n]->getMessage());

                        continue;   // the next run writes it
                    }
                    $avg = round((float) $people[$name]->avg('score'), 2);
                    $before = $previous[$name] ?? null;

                    CallReportPersonReview::query()->updateOrCreate(
                        ['organization_id' => $organization->getKey(), 'person' => $name, 'period' => $weekly ? 'weekly' : 'daily', 'from' => $from->toDateString()],
                        [
                            'to' => $to->toDateString(),
                            'calls' => $people[$name]->count(),
                            'avg_score' => $avg,
                            'summary' => mb_substr(trim((string) $answers[$n]['summary']), 0, 600),
                            'strengths' => array_slice(array_map('strval', (array) $answers[$n]['strengths']), 0, 2),
                            'work_on' => array_slice(array_map('strval', (array) $answers[$n]['work_on']), 0, 2),
                            'trend' => $before === null ? null : match (true) {
                                $avg >= $before + 0.3 => 'up',
                                $avg <= $before - 0.3 => 'down',
                                default => 'flat',
                            },
                        ],
                    );
                }
            });
        }

        return self::SUCCESS;
    }

    /** One row per scored call in the window, with who answered it. */
    private function calls(string $organizationId, CarbonImmutable $from, CarbonImmutable $to)
    {
        return DB::table('call_report_scores as s')
            ->join('call_report_transcripts as t', fn ($j) => $j->on('t.organization_id', '=', 's.organization_id')->on('t.call_id', '=', 's.call_id'))
            ->where('s.organization_id', $organizationId)
            ->whereNotNull('s.score')
            ->whereNotNull('t.answered_by')
            ->whereBetween('t.call_started_at', [$from->utc(), $to->utc()])
            ->get(['t.answered_by', 's.score', 's.checks', 's.strength', 's.improve']);
    }

    private static function prompt(bool $weekly, $calls): string
    {
        $lines = $calls->map(function ($c) {
            $checks = json_decode((string) $c->checks, true) ?: [];
            $missed = implode(', ', array_keys(array_filter($checks, fn ($v) => $v === false)));

            return "- score {$c->score}/10".($missed ? "; missed: {$missed}" : '')
                .($c->strength ? "; did well: {$c->strength}" : '').($c->improve ? "; improve: {$c->improve}" : '');
        })->implode("\n");

        return 'You coach a dispatcher at a home-services business. Below are AI reviews of their calls '
            .($weekly ? 'this week' : 'yesterday').". Write:\n"
            ."- summary: 2-3 plain sentences on how they handle calls overall.\n"
            ."- strengths: exactly 2 short phrases, what they do well most often.\n"
            ."- work_on: exactly 2 short, concrete things that would most raise their bookings.\n"
            ."Base it only on the reviews; never name customers; no numbers you were not given.\n\n".$lines;
    }

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
            'work_on' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['summary', 'strengths', 'work_on'],
        'additionalProperties' => false,
    ];
}
