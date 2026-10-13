<?php

namespace App\Support\Ai;

use App\Models\Franchise;
use App\Models\Lead;
use App\Models\Organization;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "How did MA do last week?" on the staff WhatsApp line (Jose, 2026-10-13,
 * Rodrigo's report-on-request, a1leads report-tasks.js). Grok reads the
 * question into a period and a branch, the figures come from the weekly
 * PDF's own source (PeriodReport::figures) plus the leads table, and Grok
 * answers from those figures only. Rodrigo's model may not invent a number;
 * neither may this one.
 *
 * ponytail: text answers only, no PDF. Send the PDF too when people ask for it.
 */
final class ReportAsk
{
    /** Kept short so a question that is NOT a report still reaches the Library in time. */
    private const PLAN_TIMEOUT = 12;

    private const ANSWER_TIMEOUT = 40;

    public function __construct(private readonly PeriodReport $report) {}

    /**
     * The answer, or null when the question is not about the business's
     * figures, or Grok could not read it.
     */
    public function answer(Organization $organization, string $question): ?string
    {
        $branches = Franchise::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $today = CarbonImmutable::now('America/New_York');
        try {
            $plan = GrokCli::ask(
                "Today is {$today->format('l, Y-m-d')}. A staff member of a home-services business texted the "
                ."question below. Is it asking for the business's own figures (jobs, sales, revenue, leads, "
                ."cancellations, technicians, deposits, sources)? If so, give the period as inclusive dates "
                ."(\"last week\" is the previous Monday to Sunday; no period given means this month so far) "
                ."and the branch it names, exactly as written in this list, or null for all branches: "
                .$branches->map(fn (Franchise $b) => BranchName::of($b))->implode(', ')
                ."\n\nQUESTION: ".$question,
                [
                    'type' => 'object',
                    'properties' => [
                        'is_report' => ['type' => 'boolean'],
                        'from' => ['type' => 'string'],
                        'to' => ['type' => 'string'],
                        'branch' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['is_report', 'from', 'to', 'branch'],
                    'additionalProperties' => false,
                ],
                self::PLAN_TIMEOUT,
            );
        } catch (RuntimeException) {
            return null;
        }

        if (($plan['is_report'] ?? false) !== true) {
            return null;
        }

        [$from, $to] = self::period((string) $plan['from'], (string) $plan['to'], $today);
        $asked = $branches->filter(fn (Franchise $b) => $plan['branch'] === null
            || strcasecmp(BranchName::of($b), (string) $plan['branch']) === 0);
        if ($asked->isEmpty()) {
            $asked = $branches;
        }

        $figures = $asked->map(fn (Franchise $b) => BranchName::of($b).': '.json_encode(
            $this->report->figures($b, $from, $to) + ['leads' => self::leads($b, $from, $to)],
        ))->implode("\n");

        try {
            $answer = GrokCli::ask(
                "Answer the staff member's question from these figures only, {$from} to {$to}. Workiz jobs "
                ."per source: created (booked in the period) and closed (done or canceled in the period), "
                ."gross in dollars; techs with their gross and deposits; leads by source. Never invent a "
                ."number; if the figures do not answer it, say what you can and what is missing. 2-8 short "
                ."lines for WhatsApp, numbers rounded to whole dollars, the period stated once.\n"
                .GrokCli::WHATSAPP_STYLE."\n\n"
                ."FIGURES:\n{$figures}\n\nQUESTION: {$question}",
                ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false],
                self::ANSWER_TIMEOUT,
            );
        } catch (RuntimeException) {
            return null;
        }

        $reply = trim((string) ($answer['reply'] ?? ''));

        return $reply === '' ? null : mb_substr($reply, 0, 3500);
    }

    /**
     * The model's dates, or this month so far. Never after today, and a year
     * at most, so a misread cannot ask for every job ever.
     *
     * @return array{string, string}
     */
    public static function period(string $from, string $to, CarbonImmutable $today): array
    {
        try {
            $f = CarbonImmutable::parse($from, $today->getTimezone());
            $t = CarbonImmutable::parse($to, $today->getTimezone());
        } catch (\Throwable) {
            $f = $today->startOfMonth();
            $t = $today;
        }
        $t = $t->min($today);
        $f = $f->max($t->subYear())->min($t);

        return [$f->toDateString(), $t->toDateString()];
    }

    /**
     * Leads received per source, the branch's own. Never Google Local
     * Services: its counts are Google user data, and Jose ruled on 2026-10-12
     * that no Google Ads or LSA figures go to xAI (privacy page, Website 62ae8683).
     *
     * @return array<string, int>
     */
    public static function leads(Franchise $branch, string $from, string $to): array
    {
        return Lead::query()->withoutGlobalScopes()
            ->where('franchise_id', $branch->getKey())
            ->where('is_test', false)
            ->where('source', '!=', Lead::SOURCE_LOCAL_SERVICES)
            ->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->select('source', DB::raw('count(*) n'))->groupBy('source')
            ->pluck('n', 'source')->map(fn ($n) => (int) $n)->all();
    }
}
