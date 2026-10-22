<?php

namespace App\Support\Financials;

use App\Models\FinancialRepairWeek;
use App\Models\FinancialSource;
use App\Models\Franchise;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The weekly Repair Team report (Jose: the "WhatsApp Shoval Report"), per
 * place and week, Monday to Sunday, from the place's mapped repair sheet:
 * each team's Weekly Summary row gives hours, paycheck, helper, operation and
 * gas; its Job rows give parts, the tech and external shares, the jobs and
 * their amount. A value the sheet left out can be typed in (overrides), and a
 * person with the tick approves the week; changing a value reopens it.
 *
 *   total cost       paycheck + helper + operation + gas + parts
 *   company share    jobs amount − total cost − tech share − external − contracting
 *   cost from gross  total cost ÷ jobs amount
 */
final class RepairReport
{
    /** Typed-in values may fill these; each is dollars. */
    public const FIELDS = ['hours', 'paycheck', 'helper', 'avg_operation', 'gas', 'parts', 'tech_share', 'external_share', 'contracting', 'jobs_amount'];

    /** A team with jobs and none of these is missing them (helper and gas are often truly none). */
    private const EXPECTED = ['hours', 'paycheck', 'avg_operation'];

    private const SUMMARY = ['hours' => 'hours', 'paycheck' => 'employee', 'helper' => 'helper', 'avg_operation' => 'operation', 'gas' => 'gas'];

    public static function weekStart(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    public static function week(Franchise $place, string $weekStart): array
    {
        $from = self::weekStart($weekStart);
        $to = CarbonImmutable::parse($from)->addDays(6)->toDateString();
        $record = FinancialRepairWeek::query()->where('franchise_id', $place->id)->whereDate('week_start', $from)->first();
        $teams = self::teams($place->id, $from, $to, (array) ($record?->overrides ?? []));

        $techs = array_map(fn (string $team, array $v) => ['name' => $team] + self::figures($v), array_keys($teams), $teams);
        $sum = [];
        foreach ($teams as $v) {
            foreach ($v as $k => $x) {
                if ($k !== 'missing' && $x !== null) {
                    $sum[$k] = ($sum[$k] ?? 0) + $x;
                }
            }
        }
        $combined = self::figures($sum + ['missing' => []]) + ['count' => count($teams)];
        $approver = $record?->approved_by_user_id ? User::query()->whereKey($record->approved_by_user_id)->value('name') : null;

        $report = [
            'company' => ['id' => $place->id, 'name' => trim(($place->company?->name ? $place->company->name.' - ' : '').$place->name)],
            'from' => $from, 'to' => $to,
            'status' => $record?->approved_at ? 'approved' : 'draft',
            'approved_by' => $approver, 'approved_at' => $record?->approved_at?->toIso8601String(),
            'techs' => $techs, 'combined' => $combined,
        ];

        return $report + ['text' => self::text($report)];
    }

    /**
     * Average production cost per job (tech + helper + operation + gas + parts),
     * by month, place and team.
     *
     * @return list<array{month:string, franchise_id:string, place:string, team:string, jobs:int, cost_minor:int, avg_minor:?int}>
     */
    public static function production(string $from, string $to, ?string $franchiseId = null): array
    {
        $out = [];
        $places = FinancialSource::query()->where('role', 'repair')->whereNotNull('franchise_id')
            ->when($franchiseId, fn ($q) => $q->where('franchise_id', $franchiseId))->pluck('franchise_id')->unique();
        foreach (Franchise::query()->whereIn('id', $places)->get() as $place) {
            for ($w = CarbonImmutable::parse(self::weekStart($from)); $w->toDateString() <= $to; $w = $w->addWeek()) {
                $overrides = (array) (FinancialRepairWeek::query()->where('franchise_id', $place->id)->whereDate('week_start', $w)->value('overrides') ?? []);
                foreach (self::teams($place->id, $w->toDateString(), $w->addDays(6)->toDateString(), $overrides) as $team => $v) {
                    $f = self::figures($v);
                    $row = &$out[$w->format('Y-m').'|'.$place->id.'|'.$team];
                    $row ??= ['month' => $w->format('Y-m'), 'franchise_id' => $place->id, 'place' => $place->name, 'team' => $team, 'jobs' => 0, 'cost_minor' => 0];
                    $row['jobs'] += $f['jobs_done'];
                    $row['cost_minor'] += $f['total_cost_minor'];
                    unset($row);
                }
            }
        }
        ksort($out);

        return array_values(array_map(fn ($r) => $r + ['avg_minor' => $r['jobs'] > 0 ? intdiv($r['cost_minor'], $r['jobs']) : null], $out));
    }

    /** Sets one typed-in value (dollars, null clears it); reopens an approved week. */
    public static function setValue(Franchise $place, string $weekStart, string $team, string $field, ?float $dollars): void
    {
        $record = FinancialRepairWeek::query()->firstOrCreate(['franchise_id' => $place->id, 'week_start' => self::weekStart($weekStart)]);
        $overrides = (array) ($record->overrides ?? []);
        if ($dollars === null) {
            unset($overrides[$team][$field]);
        } else {
            $overrides[$team][$field] = $dollars;
        }
        $record->forceFill(['overrides' => array_filter($overrides), 'approved_at' => null, 'approved_by_user_id' => null])->save();
    }

    public static function approve(Franchise $place, string $weekStart, User $user): void
    {
        FinancialRepairWeek::query()->firstOrCreate(['franchise_id' => $place->id, 'week_start' => self::weekStart($weekStart)])
            ->forceFill(['approved_at' => now(), 'approved_by_user_id' => $user->id])->save();
    }

    /** The week as text for "Copy for WhatsApp". */
    public static function text(array $r): string
    {
        $money = fn (?int $m) => $m === null ? '—' : '$'.number_format($m / 100, 2);
        $lines = ["*{$r['company']['name']} · Weekly {$r['from']} to {$r['to']}*".($r['status'] === 'approved' ? '' : ' · UNAPPROVED DRAFT'), ''];
        foreach ([...$r['techs'], ['name' => "All repair teams combined ({$r['combined']['count']})"] + $r['combined']] as $t) {
            $lines[] = "*{$t['name']}*";
            $lines[] = 'Hours: '.($t['hours'] ?? '—').' · Paycheck: '.$money($t['paycheck_minor']).' · Helper: '.$money($t['helper_minor']);
            $lines[] = 'Operation: '.$money($t['avg_operation_minor']).' · Gas: '.$money($t['gas_minor']).' · Parts: '.$money($t['parts_minor']);
            $lines[] = 'Total cost: '.$money($t['total_cost_minor']);
            foreach (['tech_share' => 'Tech share', 'external_share' => 'External share', 'contracting_profit' => 'Contracting profit', 'company_share' => 'Company share'] as $k => $label) {
                $lines[] = "{$label}: ".$money($t[$k]['minor']).($t[$k]['pct'] === null ? '' : " ({$t[$k]['pct']}%)");
            }
            $lines[] = "Jobs: {$t['jobs_done']} · ".$money($t['jobs_amount_minor']).($t['cost_from_gross_pct'] === null ? '' : " · Cost from gross {$t['cost_from_gross_pct']}%");
            if ($t['missing'] !== []) {
                $lines[] = 'Missing: '.implode(', ', $t['missing']);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /** @return array<string, array<string, mixed>> team => cents per field, jobs, missing */
    private static function teams(string $franchiseId, string $from, string $to, array $overrides): array
    {
        $teams = [];
        foreach (SourceFigures::sources('repair', $from, $to, $franchiseId) as $source) {
            foreach (SourceFigures::rows($source, $from, $to) as $f) {
                $team = trim((string) SourceFigures::value($source, $f, 'team'));
                if ($team === '') {
                    continue;
                }
                $t = &$teams[$team];
                $t ??= array_fill_keys(self::FIELDS, null) + ['jobs' => 0];
                $kind = (string) SourceFigures::value($source, $f, 'kind');
                if (strcasecmp($kind, $source->marker('summary') ?? 'Weekly Summary') === 0) {
                    foreach (self::SUMMARY as $field => $key) {
                        self::add($t, $field, SourceFigures::value($source, $f, $key), $field === 'hours');
                    }
                } elseif (strcasecmp($kind, $source->marker('job') ?? 'Job') === 0) {
                    $t['jobs']++;
                    foreach (['parts' => 'parts', 'tech_share' => 'tech_share', 'external_share' => 'external_share', 'contracting' => 'contracting', 'jobs_amount' => 'job_amount'] as $field => $key) {
                        self::add($t, $field, SourceFigures::value($source, $f, $key));
                    }
                }
                unset($t);
            }
        }
        foreach ($overrides as $team => $values) {
            $teams[$team] ??= array_fill_keys(self::FIELDS, null) + ['jobs' => 0];
            foreach ((array) $values as $field => $dollars) {
                if (in_array($field, self::FIELDS, true)) {
                    $teams[$team][$field] = $field === 'hours' ? (float) $dollars : (int) round($dollars * 100);
                }
            }
        }
        foreach ($teams as &$t) {
            $t['missing'] = $t['jobs'] > 0 ? array_values(array_filter(self::EXPECTED, fn ($k) => $t[$k] === null)) : [];
        }
        ksort($teams);

        return $teams;
    }

    private static function add(array &$t, string $field, mixed $raw, bool $plain = false): void
    {
        if (is_numeric($raw)) {
            $t[$field] = ($t[$field] ?? 0) + ($plain ? (float) $raw : (int) round($raw * 100));
        }
    }

    private static function figures(array $v): array
    {
        $c = fn (string $k) => isset($v[$k]) ? $v[$k] : null;
        $total = (int) array_sum(array_map(fn ($k) => $c($k) ?? 0, ['paycheck', 'helper', 'avg_operation', 'gas', 'parts']));
        $amount = $c('jobs_amount');
        $pct = fn (?int $m) => $m === null || ! $amount ? null : round($m * 100 / $amount, 1);
        $share = fn (?int $m) => ['minor' => $m, 'pct' => $pct($m)];
        $company = $amount === null ? null : $amount - $total - ($c('tech_share') ?? 0) - ($c('external_share') ?? 0) - ($c('contracting') ?? 0);

        return [
            'hours' => $c('hours'), 'paycheck_minor' => $c('paycheck'), 'helper_minor' => $c('helper'),
            'avg_operation_minor' => $c('avg_operation'), 'gas_minor' => $c('gas'), 'parts_minor' => $c('parts'),
            'total_cost_minor' => $total,
            'tech_share' => $share($c('tech_share')), 'external_share' => $share($c('external_share')),
            'contracting_profit' => $share($c('contracting')), 'company_share' => $share($company),
            'jobs_done' => (int) ($v['jobs'] ?? 0), 'jobs_amount_minor' => $amount,
            'cost_from_gross_pct' => $pct($total),
            'missing' => $v['missing'] ?? [],
        ];
    }
}
