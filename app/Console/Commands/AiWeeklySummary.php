<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\LeadsController;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\Organization;
use App\Support\Ai\GrokCli;
use App\Support\Automations\EventEmitter;
use App\Support\Reports\ReportJobs;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Last week in plain English, written by Grok from the same figures as the
 * weekly PDF plus the AI call labels (Jose, 2026-10-12: "report via whatsapp
 * and email, work with automations and reports on this"). Raises
 * reports.ai_summary_ready; the Automations rule decides who gets it - email,
 * and the staff WhatsApp group as free text (Jose's pick).
 */
class AiWeeklySummary extends Command
{
    protected $signature = 'ezbizi:ai-weekly-summary {--dry : print the summary, raise nothing}';

    protected $description = 'Have Grok write last week in plain English and raise reports.ai_summary_ready';

    public function handle(TenantContext $tenant, EventEmitter $events, PeriodReport $report): int
    {
        if (! GrokCli::configured()) {
            return self::SUCCESS;
        }

        $to = CarbonImmutable::now('America/New_York')->startOfWeek()->subDay();   // last Sunday
        $from = $to->subDays(6);

        foreach (Organization::query()->withoutGlobalScopes()->get() as $organization) {
            try {
                $tenant->run($organization, function () use ($organization, $events, $report, $from, $to) {
                    $figures = $this->figures($organization, $report, $from->toDateString(), $to->toDateString());
                    if ($figures === '') {
                        return;
                    }

                    $answer = GrokCli::ask(
                        'You write a short weekly note for the owner of a home-services business (chimneys, '
                        ."fireplaces, garage doors). Below are last week's figures ({$from->toFormattedDateString()} "
                        ."to {$to->toFormattedDateString()}). Write:\n"
                        ."- headline: one line, the single most important thing.\n"
                        .'- summary: 5 to 8 short plain-English lines: what went well, what went badly, '
                        .'which branch stands out either way, and one or two things to do this week. Use the '
                        ."numbers; never invent one. No greetings, no sign-off.\n"
                        .'The summary goes to a WhatsApp group: '.GrokCli::WHATSAPP_STYLE."\n\n".$figures,
                        [
                            'type' => 'object',
                            'properties' => ['headline' => ['type' => 'string'], 'summary' => ['type' => 'string']],
                            'required' => ['headline', 'summary'],
                            'additionalProperties' => false,
                        ],
                    );

                    $summary = trim((string) $answer['summary'])
                        .(($check = self::accuracy($organization->getKey(), $from, $to)) ? "\n\n".$check : '')
                        ."\n\nWritten by EzBizi AI.";

                    if ($this->option('dry')) {
                        $this->line($organization->name.': '.$answer['headline']."\n".$summary);

                        return;
                    }

                    $events->emit('reports.ai_summary_ready', null, ['after' => [
                        'headline' => trim((string) $answer['headline']),
                        'summary' => $summary,
                        'period_start' => $from->toDateString(),
                        'period_end' => $to->toDateString(),
                    ]]);
                });
            } catch (Throwable $e) {
                report($e);
                $this->error($organization->name.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * How often people kept what AI set on leads last week (Jose, 2026-10-12,
     * the weekly accuracy check): a value changed since is a value AI got wrong.
     */
    public static function accuracy(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        $leads = Lead::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('ai_set')
            ->whereBetween('classified_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->get(['id', 'status', 'contact_name', 'postal_code', 'ai_set']);

        $set = $kept = 0;
        foreach ($leads as $lead) {
            $fields = array_intersect(array_keys($lead->ai_set), ['status', 'contact_name', 'postal_code']);
            $set += count($fields);
            $kept += count(LeadsController::aiHeld($lead));
        }

        return $set === 0 ? null : sprintf('AI check: %d values set on %d leads, %d changed by a person (%d%% kept).',
            $set, $leads->count(), $set - $kept, round(100 * $kept / $set));
    }

    /** The PDF's figures as text, branch by branch, plus call reasons. Empty when there are none. */
    private function figures(Organization $organization, PeriodReport $report, string $from, string $to): string
    {
        $branches = Franchise::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->whereIn('id', ReportJobs::branchesWithJobs())
            ->orderBy('name')
            ->get();

        $text = '';
        foreach ($branches as $branch) {
            // ponytail: the PDF's own page, tags stripped - one source of figures.
            $text .= preg_replace('/[ \t]+/', ' ', strip_tags(str_replace(['</td>', '</tr>', '</h1>', '</h2>'], [' | ', "\n", "\n", "\n"],
                $report->html($branch, $from, $to))))."\n\n";
        }

        // ponytail: counted by when the label was written, not when the call
        // was made, so a --relabel run inflates that week; join the transcript's
        // call date if that matters.
        $labels = DB::table('call_report_labels')
            ->where('organization_id', $organization->getKey())
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereNotNull('label')
            ->selectRaw('label, count(*) as n')->groupBy('label')->orderByDesc('n')
            ->pluck('n', 'label');

        if ($labels->isNotEmpty()) {
            $text .= 'Calls by reason (AI-labelled): '.$labels->map(fn ($n, $l) => "$l $n")->implode(', ')."\n";
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
