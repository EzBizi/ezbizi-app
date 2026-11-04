<?php

namespace App\Support\Reports\Texts;

use App\Models\Franchise;
use App\Models\Organization;
use App\Support\Reports\ReportJobs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The nightly WhatsApp Same-Day Report, one per branch, and the all-branches
 * summary: EzBizi's replacement for the Rodrigo bot's (Jose, 2026-10-06: "Yes,
 * replace Rodrigo"; docs/REPLACING-RODRIGO-BOT.md, reports A and B). The text
 * follows Rodrigo's report-engine/daily-text.js line for line, so the groups
 * read the same report they always have. The Meta session delivers it.
 *
 * Every figure comes from what EzBizi mirrors from Workiz and Google, read by
 * branch id. Workiz writes its stamps in the account's own clock, so a day is
 * compared as wall-clock text, never converted.
 *
 * A section whose source is not current prints "Unavailable" instead of a
 * number that looks real.
 */
final class SameDayReport
{
    /** WhatsApp's text ceiling. Over it, throw: never cut facts off silently. */
    public const LIMIT = 4096;

    /** Workiz jobs land every minute, payments every 10 (SyncWorkiz*). */
    private const JOBS_STALE_MINUTES = 30;

    private const PAYMENTS_STALE_MINUTES = 60;

    private const DASH = '—';

    public static function forFranchise(Franchise $branch, string $date): string
    {
        $f = self::figures($branch, $date);
        $zone = self::zone($branch);

        $lines = [
            '📊 *'.BranchName::of($branch).' · Same-Day Report*',
            self::dateLabel($date),
            self::asOf($date, $zone),
            '',
            '🔧 *1. Jobs created per ad source*',
            ...self::jobLines($f['jobs'], $f['sources'], 'No ad-source jobs were created.', 'Workiz sources did not refresh'),
            '',
            '📍 *2. Jobs created by service area*',
            ...self::jobLines($f['jobs'], $f['areas'], 'No Workiz service areas were returned.', 'Workiz service areas did not refresh'),
            '',
            '⭐ *3. Google Reviews (mapped metro areas)*',
            ...self::reviewLines($f['reviews']),
            '',
            '👷 *4. Field performance*',
            ...self::fieldLines($f),
            '',
            '_Ask me about any location, technician, source, or job behind these numbers._',
        ];

        return self::fit(implode("\n", $lines), $branch->name);
    }

    public static function summary(Organization $organization, string $date): string
    {
        $branches = Franchise::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->whereIn('id', ReportJobs::branchesWithJobs())
            ->orderBy('name')
            ->get();

        $sources = [];
        $rows = [];
        $verified = 0;
        $totals = ['jobs' => 0, 'completed' => 0, 'canceled' => 0, 'sales' => 0.0, 'deposits' => null, 'new' => null];

        foreach ($branches as $branch) {
            $f = self::figures($branch, $date);

            // Only a branch whose Workiz data is current is a verified report.
            if ($f['jobs'] === null) {
                continue;
            }

            foreach ($f['sources'] as $name => $n) {
                $sources[$name] = ($sources[$name] ?? 0) + $n;
            }

            $field = self::fieldTotals($f);
            $row = [
                'jobs' => $f['jobs'],
                'completed' => $field['completed'],
                'canceled' => $field['canceled'],
                'sales' => $field['sales'],
                'deposits' => $f['deposits'] === null ? null : $field['deposits'],
                'new' => $f['reviews']['new'] ?? null,
            ];

            // One branch, a few short lines (Jose, 2026-10-13: "use emojis ...
            // line breaks"), a blank line between branches.
            $verified++;
            $rows[] = '*'.BranchName::of($branch).'*';
            $rows[] = "🔧 *{$row['jobs']}* jobs · ✅ {$row['completed']} done · ❌ {$row['canceled']} canceled";
            $rows[] = '💰 *'.self::money($row['sales']).'* sales · 💵 '.self::money($row['deposits']).' deposits';
            $rows[] = '⭐ '.self::count($row['new']).' new reviews';
            $rows[] = '';

            foreach ($totals as $key => $value) {
                // Deposits and reviews stay a dash until one branch has them.
                $totals[$key] = $row[$key] === null ? $value : ($value ?? 0) + $row[$key];
            }
        }

        arsort($sources);
        $zone = self::zone($branches->first());

        $lines = [
            "📊 *{$organization->name} · Same-Day Company Summary*",
            self::dateLabel($date),
            self::asOf($date, $zone),
            '_'.$verified.' verified company reports_',
            '',
            '🔧 *Jobs created per ad source*',
            ...($sources === []
                ? ['No ad-source jobs were created.']
                : array_map(fn ($name, $n) => "• *{$name}:* {$n}", array_keys($sources), $sources)),
            '*Total: '.self::jobs($totals['jobs']).'*',
            '',
            '🏢 *Company summary*',
            '',
            ...$rows,
            '*All companies*',
            "🔧 *{$totals['jobs']}* jobs · ✅ {$totals['completed']} done · ❌ {$totals['canceled']} canceled",
            '💰 *'.self::money($totals['sales']).'* sales · 💵 '.self::money($totals['deposits']).' deposits',
            '⭐ '.self::count($totals['new']).' new reviews',
        ];

        return self::fit(implode("\n", $lines), 'the company summary');
    }

    /**
     * One branch's day. A null section means its source is not current.
     *
     * @return array{jobs: ?int, sources: array<string,int>, areas: array<string,int>,
     *   techs: ?array<string, array{name: string, completed: int, canceled: int, sales: float}>,
     *   deposits: ?array<string, array{name: string, amount: float}>, reviews: ?array}
     */
    private static function figures(Franchise $branch, string $date): array
    {
        $day = [$date.' 00:00:00', $date.' 23:59:59'];
        $jobs = ReportJobs::jobs((string) $branch->getKey());
        $fresh = fn ($what, $minutes) => ($last = ReportJobs::syncedAt((string) $branch->getKey(), $what)) !== null
            && CarbonImmutable::parse($last)->greaterThan(now()->subMinutes($minutes));

        $out = ['jobs' => null, 'sources' => [], 'areas' => [], 'techs' => null, 'deposits' => null, 'reviews' => self::reviews($branch, $date)];

        if ($fresh('jobs', self::JOBS_STALE_MINUTES)) {
            $booked = (clone $jobs)->whereBetween('j.booked_at', $day)->get();
            $out['jobs'] = $booked->count();
            $out['sources'] = self::tally($booked->pluck('job_source'), 'No source');
            $out['areas'] = self::tally($booked->pluck('service_area'), 'No service area');

            // ponytail: last_status_update is the job's latest change, so a job
            // done today and edited tomorrow leaves today's count; Workiz has no
            // "completed at" in the API.
            $techs = [];
            $closed = (clone $jobs)->whereBetween('j.last_status_update', $day)
                ->whereIn('j.status', ['Done', 'Canceled'])->get();

            foreach ($closed as $job) {
                $team = ReportJobs::team($job);
                $done = $job->status === 'Done';
                // A job worked by two shares its price, so the total stays the job's.
                $share = $done ? (float) $job->total / count($team) : 0.0;

                foreach ($team as $tech) {
                    $id = (string) ($tech['id'] ?? $tech['Name'] ?? 'none');
                    $techs[$id] ??= ['name' => trim((string) ($tech['Name'] ?? 'Unassigned')), 'completed' => 0, 'canceled' => 0, 'sales' => 0.0];
                    $techs[$id][$done ? 'completed' : 'canceled']++;
                    $techs[$id]['sales'] += $share;
                }
            }

            $out['techs'] = $techs;
        }

        if ($fresh('payments', self::PAYMENTS_STALE_MINUTES)) {
            $names = ReportJobs::people((string) $branch->getKey());

            $out['deposits'] = [];
            foreach (ReportJobs::paidBy((string) $branch->getKey(), ...$day) as $ref => $amount) {
                $out['deposits'][(string) $ref] = [
                    'name' => PeriodReport::collector($names[$ref] ?? null, (string) $ref),
                    'amount' => $amount,
                ];
            }
        }

        return $out;
    }

    /** New reviews on the day, and where each linked Google location stands. */
    private static function reviews(Franchise $branch, string $date): ?array
    {
        $locations = DB::table('business_locations')->where('franchise_id', $branch->getKey())->get(['remote_name', 'title']);

        if ($locations->isEmpty()) {
            return null;
        }

        $zone = self::zone($branch);
        $from = CarbonImmutable::parse($date, $zone)->startOfDay()->utc();
        $reviews = DB::table('reviews')->whereIn('location_name', $locations->pluck('remote_name'))->get(['location_name', 'rating', 'reviewed_at']);
        $isNew = fn ($r) => $r->reviewed_at !== null
            && CarbonImmutable::parse($r->reviewed_at, 'UTC')->between($from, $from->addDay()->subSecond());

        $rows = $locations->map(fn ($l) => [
            'label' => $l->title ?: $l->remote_name,
            'new' => $reviews->where('location_name', $l->remote_name)->filter($isNew)->count(),
            'total' => $reviews->where('location_name', $l->remote_name)->count(),
            'avg' => $reviews->where('location_name', $l->remote_name)->avg('rating'),
        ])->all();

        return [
            'rows' => $rows,
            'new' => $reviews->filter($isNew)->count(),
            'byStar' => $reviews->filter($isNew)->countBy('rating')->all(),
            'total' => $reviews->count(),
            'avg' => $reviews->avg('rating'),
            'locations' => $locations->count(),
        ];
    }

    private static function jobLines(?int $jobs, array $rows, string $none, string $unavailable): array
    {
        if ($jobs === null) {
            return [self::unavailable($unavailable)];
        }

        $lines = $rows === [] ? [$none] : array_map(fn ($name, $n) => "• *{$name}:* {$n}", array_keys($rows), $rows);
        $lines[] = '*Total: '.self::jobs($jobs).'*';

        return $lines;
    }

    private static function reviewLines(?array $r): array
    {
        if ($r === null) {
            return [self::unavailable('no Google Business Profile locations are linked to this branch')];
        }

        $rating = fn ($total, $avg) => $total > 0 ? number_format((float) $avg, 1).'★' : 'no rating';
        $lines = array_map(
            fn ($row) => "• *{$row['label']}:* {$row['new']} new · {$row['total']} ".($row['total'] === 1 ? 'review' : 'reviews').' · '.$rating($row['total'], $row['avg']),
            $r['rows'],
        );

        $stars = [];
        foreach ([5, 4, 3, 2, 1] as $s) {
            if ($n = $r['byStar'][$s] ?? 0) {
                $stars[] = "{$s}★×{$n}";
            }
        }

        $lines[] = "*Subaccount: {$r['new']} new".($stars ? ' ('.implode(', ', $stars).')' : '')
            ." · {$r['total']} reviews · ".$rating($r['total'], $r['avg'])
            ." · {$r['locations']} linked ".($r['locations'] === 1 ? 'location' : 'locations').'*';

        return $lines;
    }

    private static function fieldLines(array $f): array
    {
        $workiz = $f['techs'] !== null;
        $paid = $f['deposits'] !== null;
        $rows = self::techRows($f);
        $lines = [];

        if ($rows === [] && $workiz && $paid) {
            $lines[] = 'No completed, canceled, sales, or deposit activity.';
        }

        foreach ($rows as $row) {
            if ($workiz && $paid && $row['completed'] + $row['canceled'] === 0 && $row['sales'] == 0 && $row['deposits'] != 0) {
                $lines[] = "• *{$row['name']}:* ".self::money($row['deposits'])
                    .($row['deposits'] > 0 ? ' in payments received' : ' net payment adjustment')
                    .' · no completed, canceled, or sales activity today';

                continue;
            }

            $lines[] = "• *{$row['name']}:* ".implode(' · ', self::metrics($row, $workiz, $paid));
        }

        if ($workiz || $paid) {
            $lines[] = '*Total: '.implode(' · ', self::metrics(self::fieldTotals($f), $workiz, $paid)).'*';
        }

        if (! $workiz) {
            $lines[] = self::unavailable('Workiz technician report did not refresh');
        }

        if (! $paid) {
            $lines[] = self::unavailable('Workiz Payments report did not refresh');
        }

        return $lines;
    }

    /** Each technician with jobs or payments on the day, best sales first. */
    private static function techRows(array $f): array
    {
        $rows = [];

        foreach ($f['techs'] ?? [] as $id => $t) {
            $rows[$id] = $t + ['deposits' => 0.0];
        }

        foreach ($f['deposits'] ?? [] as $id => $d) {
            $rows[$id] ??= ['name' => $d['name'], 'completed' => 0, 'canceled' => 0, 'sales' => 0.0, 'deposits' => 0.0];
            $rows[$id]['deposits'] += $d['amount'];
        }

        usort($rows, fn ($a, $b) => [$b['sales'], $b['deposits']] <=> [$a['sales'], $a['deposits']]);

        return $rows;
    }

    private static function fieldTotals(array $f): array
    {
        $rows = self::techRows($f);

        return [
            'completed' => array_sum(array_column($rows, 'completed')),
            'canceled' => array_sum(array_column($rows, 'canceled')),
            'sales' => array_sum(array_column($rows, 'sales')),
            'deposits' => array_sum(array_column($rows, 'deposits')),
        ];
    }

    private static function metrics(array $row, bool $workiz, bool $paid): array
    {
        return array_merge(
            $workiz ? ["✅ {$row['completed']} done", "❌ {$row['canceled']} canceled", '💰 '.self::money($row['sales']).' sales'] : [],
            $paid ? ['💵 '.self::money($row['deposits']).' deposits'] : [],
        );
    }

    /** @return array<string,int> name => jobs, most first. */
    private static function tally($values, string $blank): array
    {
        $out = [];
        foreach ($values as $v) {
            $name = trim((string) $v) ?: $blank;
            $out[$name] = ($out[$name] ?? 0) + 1;
        }

        arsort($out);

        return $out;
    }

    private static function zone(?Franchise $branch): string
    {
        return $branch?->timezone ?: \App\Support\Settings\OrgTimezone::of();
    }

    private static function dateLabel(string $date): string
    {
        return '📅 '.CarbonImmutable::parse($date)->format('D, M j, Y');
    }

    private static function asOf(string $date, string $zone): string
    {
        $now = CarbonImmutable::now($zone);
        $time = $now->format('g:i A T');

        return $now->toDateString() === $date
            ? "_Current through {$time}_"
            : '_Report data: '.CarbonImmutable::parse($date)->format('M j, Y').' only · recovered/refreshed '.$now->format('M j, Y')." at {$time}_";
    }

    private static function unavailable(string $why): string
    {
        return "⚠️ *Unavailable:* {$why}";
    }

    private static function jobs(int $n): string
    {
        return $n.($n === 1 ? ' job' : ' jobs');
    }

    private static function count(?int $n): string
    {
        return $n === null ? self::DASH : (string) $n;
    }

    private static function money(?float $amount): string
    {
        return $amount === null ? self::DASH : ($amount < 0 ? '-' : '').'$'.number_format(abs($amount), 2);
    }

    private static function fit(string $text, string $what): string
    {
        $length = mb_strlen($text);

        if ($length > self::LIMIT) {
            throw new RuntimeException("The Same-Day Report for {$what} is {$length} characters (WhatsApp's limit is ".self::LIMIT.').');
        }

        return $text;
    }
}
