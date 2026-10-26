<?php

namespace App\Support\Financials;

use App\Models\FinancialRepairWeek;
use App\Models\Franchise;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What EzBizi adds to the weekly Repair Team report (Jose: the "WhatsApp
 * Shoval Report", read by SourceFigures::repairReport): a week's approval,
 * values typed in where the sheet left one out (an edit reopens an approved
 * week), its text for "Copy for WhatsApp", and the average production cost.
 */
final class RepairReport
{
    /** Values that can be typed in; dollars, hours as hours. The card's own names. */
    public const FIELDS = ['hours', 'paycheck', 'helper', 'operation', 'gas', 'parts', 'tech_share', 'external_share', 'contracting', 'jobs_amount'];

    public static function weekStart(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    public static function weekEnd(string $date): string
    {
        return CarbonImmutable::parse(self::weekStart($date))->addDays(6)->toDateString();
    }

    public static function isWeek(string $from, string $to): bool
    {
        return self::weekStart($from) === $from && self::weekEnd($from) === $to;
    }

    /** Sets one typed-in value (null clears it); reopens an approved week. */
    public static function setValue(Franchise $place, string $weekStart, string $team, string $field, ?float $value): void
    {
        $record = FinancialRepairWeek::query()->firstOrCreate(['franchise_id' => $place->id, 'week_start' => self::weekStart($weekStart)]);
        $overrides = (array) ($record->overrides ?? []);
        if ($value === null) {
            unset($overrides[$team][$field]);
        } else {
            $overrides[$team][$field] = $value;
        }
        $record->forceFill(['overrides' => array_filter($overrides), 'approved_at' => null, 'approved_by_user_id' => null])->save();
    }

    public static function approve(Franchise $place, string $weekStart, User $user): void
    {
        FinancialRepairWeek::query()->firstOrCreate(['franchise_id' => $place->id, 'week_start' => self::weekStart($weekStart)])
            ->forceFill(['approved_at' => now(), 'approved_by_user_id' => $user->id])->save();
    }

    /**
     * Average production cost per job (tech + helper + operation + gas + parts)
     * by month, place and team, week by week so typed-in values count.
     *
     * @return list<array{month:string, franchise_id:?string, place:?string, team:string, jobs:int, cost_cents:int, avg_cents:?int}>
     */
    public static function production(string $from, string $to, ?string $franchiseId = null): array
    {
        $out = [];
        for ($w = CarbonImmutable::parse(self::weekStart($from)); $w->toDateString() <= $to; $w = $w->addWeek()) {
            foreach (SourceFigures::repairReport($w->toDateString(), $w->addDays(6)->toDateString(), $franchiseId) as $place) {
                foreach ($place['technicians'] as $card) {
                    $row = &$out[$w->format('Y-m').'|'.$place['franchise_id'].'|'.$card['technician']];
                    $row ??= ['month' => $w->format('Y-m'), 'franchise_id' => $place['franchise_id'], 'place' => $place['place'], 'team' => $card['technician'], 'jobs' => 0, 'cost_cents' => 0];
                    $row['jobs'] += $card['jobs'];
                    $row['cost_cents'] += $card['total_cost_cents'];
                    unset($row);
                }
            }
        }
        ksort($out);

        return array_values(array_map(fn ($r) => $r + ['avg_cents' => $r['jobs'] > 0 ? intdiv($r['cost_cents'], $r['jobs']) : null], $out));
    }

    /** One place's report as text for "Copy for WhatsApp". */
    public static function text(array $r): string
    {
        $money = fn (int $c) => '$'.number_format($c / 100, 2);
        $title = trim(($r['company'] && $r['company'] !== $r['place'] ? $r['company'].' - ' : '').$r['place']);
        $lines = ["*{$title} · Weekly {$r['from']} to {$r['to']}*".($r['week_status'] === 'approved' ? '' : ' · UNAPPROVED DRAFT'), ''];
        foreach ([...$r['technicians'], ['technician' => "All repair teams combined ({$r['combined']['count']})", 'missing' => []] + $r['combined']] as $t) {
            $lines[] = "*{$t['technician']}*";
            $lines[] = "Hours: {$t['hours']} · Paycheck: ".$money($t['paycheck_cents']).' · Helper: '.$money($t['helper_cents']);
            $lines[] = 'Operation: '.$money($t['operation_cents']).' · Gas: '.$money($t['gas_cents']).' · Parts: '.$money($t['parts_cents']);
            $lines[] = 'Total cost: '.$money($t['total_cost_cents']);
            foreach (['tech_share' => 'Tech share', 'external_share' => 'External share', 'contracting_profit' => 'Contracting profit', 'company_share' => 'Company share'] as $k => $label) {
                $lines[] = "{$label}: ".$money($t[$k]['cents']).($t[$k]['pct'] === null ? '' : " ({$t[$k]['pct']}%)");
            }
            $lines[] = "Jobs: {$t['jobs']} · ".$money($t['jobs_amount_cents']).($t['cost_from_gross_pct'] === null ? '' : " · Cost from gross {$t['cost_from_gross_pct']}%");
            if ($t['missing'] !== []) {
                $lines[] = 'Missing: '.implode(', ', $t['missing']);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }
}
