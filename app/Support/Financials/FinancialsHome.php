<?php

namespace App\Support\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialAdjustment;
use App\Models\FinancialBase;
use App\Models\FinancialBill;
use App\Models\FinancialRecord;
use App\Models\FinancialSource;
use App\Models\FinancialTable;
use App\Models\User;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;

/**
 * The Financials home (UI-UX option 1, Jose 2026-10-20): one card per area of
 * the books, then the organization's own bases. An area exists for a tenant
 * when it has mapped Airtable sources or native records in it, so a business
 * with no Airtable gets only native cards. Everything is limited to the
 * places the current view covers.
 */
final class FinancialsHome
{
    /** area key => [name, icon, colour hint, source roles] */
    public const AREAS = [
        'refunds' => ['Disputes & Refunds', 'undo', 0, ['refunds', 'disputes', 'check_returns']],
        'deposits' => ['Deposits', 'savings', 1, ['deposits']],
        'repair' => ['Repair Teams', 'build', 2, ['repair']],
        'pnl' => ['Profit & Loss', 'trending_up', 3, ['pnl']],
        'invoices' => ['Invoices & HQ expenses', 'receipt_long', 4, ['hq_expenses', 'weekly_data', 'monthly_bills', 'lease_reviews', 'marketing', 'office_per_job', 'companies']],
        'payroll' => ['Technician Payroll', 'payments', 5, ['payroll']],
    ];

    /** @return array{areas: list<array>, bases: list<array>, may_create_base: bool} */
    public static function for(User $user): array
    {
        $scope = ViewScope::forUser($user);
        $sources = FinancialSource::query()->get()->filter(fn (FinancialSource $s) => $s->franchise_id === null
            ? $scope->isOrganization()
            : $scope->coversFranchise($s->franchise_id));
        $weekFrom = now()->startOfWeek()->toDateString();
        $weekTo = now()->endOfWeek()->toDateString();
        $monthFrom = now()->startOfMonth()->toDateString();

        $areas = [];
        foreach (self::AREAS as $key => [$name, $icon, $colour, $roles]) {
            $mine = $sources->whereIn('role', $roles);
            $native = self::native($key, $scope);
            if ($mine->isEmpty() && $native['tables'] === []) {
                continue;
            }

            $areas[] = [
                'key' => $key,
                'name' => $name,
                'icon' => $icon,
                'colour_hint' => $colour,
                'figure' => self::figure($key, $mine, $native, $weekFrom, $weekTo, $monthFrom, $user),
                'source' => $mine->isEmpty() ? 'native' : ($native['tables'] === [] ? 'airtable' : 'both'),
                'refreshed_at' => self::refreshed($mine, $native['at']),
                'tables' => [...self::airtableTables($mine), ...$native['tables']],
            ];
        }

        $bases = InventoryVisibility::constrain(FinancialBase::query(), $user, $scope)
            ->with('tables:id,base_id,source_table_id,synced_at,updated_at')->withCount('tables')->orderBy('name')->get()
            ->map(function (FinancialBase $b) {
                $mirrored = $b->tables->whereNotNull('source_table_id')->count();
                $records = FinancialRecord::query()->whereIn('table_id', $b->tables->pluck('id'))->count();

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'figure' => $b->tables_count.' '.str('table')->plural($b->tables_count).' · '.number_format($records).' '.str('record')->plural($records),
                    'source' => $mirrored === 0 ? 'native' : ($mirrored === $b->tables_count ? 'airtable' : 'both'),
                    'refreshed_at' => ($b->tables->max('synced_at') ?? $b->tables->max('updated_at') ?? $b->updated_at)?->toIso8601String(),
                ];
            })->values()->all();

        return ['areas' => $areas, 'bases' => $bases, 'may_create_base' => $user->hasPermission('financials.create')];
    }

    /** @return array{tables: list<array>, at: ?string, rows: Collection} */
    private static function native(string $area, ViewScope $scope): array
    {
        $inView = fn ($row) => $row->franchise_id === null ? $scope->isOrganization() : $scope->coversFranchise($row->franchise_id);

        [$rows, $table] = match ($area) {
            'refunds' => [FinancialAdjustment::query()->whereIn('kind', ['refund', 'dispute', 'check_return'])->get(), ['key' => 'adjustments', 'name' => 'Recorded in EzBizi', 'table_id' => null, 'route' => '/financials/adjustments']],
            'repair' => [FinancialAdjustment::query()->where('kind', 'repair_move')->get(), ['key' => 'repair_moves', 'name' => 'Split changes', 'table_id' => null, 'route' => '/financials/adjustments?kind=repair_move']],
            'invoices' => [FinancialBill::query()->get(), ['key' => 'bills', 'name' => 'Bills', 'table_id' => null, 'route' => '/financials/bills']],
            default => [collect(), null],
        };
        $rows = $rows->filter($inView)->values();

        return [
            'tables' => $table === null || $rows->isEmpty() ? [] : [$table + ['count' => $rows->count()]],
            'at' => $rows->max('updated_at')?->toIso8601String(),
            'rows' => $rows,
        ];
    }

    /** One pre-worded line per card. */
    private static function figure(string $area, Collection $sources, array $native, string $weekFrom, string $weekTo, string $monthFrom, User $user): string
    {
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);
        $placeFilter = fn (array $rows) => array_values(array_filter($rows, fn ($r) => ViewScope::forUser($user)->coversFranchise($r['franchise_id'])));

        return match ($area) {
            'refunds' => (function () use ($weekFrom, $weekTo, $money, $placeFilter) {
                $rows = $placeFilter(SourceFigures::refunds($weekFrom, $weekTo));
                $refunded = array_sum(array_map(fn ($r) => $r['kind'] === 'refund' ? $r['total_cents'] : 0, $rows));
                $disputes = array_sum(array_map(fn ($r) => $r['kind'] === 'dispute' ? count($r['items']) : 0, $rows));

                return "This week: {$money($refunded)} refunded · {$disputes} ".str('dispute')->plural($disputes);
            })(),
            'repair' => (function () use ($weekFrom, $weekTo, $placeFilter) {
                $groups = collect($placeFilter(SourceFigures::repairSplit($weekFrom, $weekTo)))->flatMap(fn ($p) => $p['groups']);
                $jobs = $groups->sum('jobs');

                return "This week: {$jobs} ".str('job')->plural($jobs).' across '.$groups->count().' '.str('team')->plural($groups->count());
            })(),
            'invoices' => (function () use ($native, $monthFrom, $money) {
                $bills = $native['rows']->filter(fn ($b) => $b->period_to?->toDateString() >= $monthFrom);

                return $bills->isEmpty()
                    ? 'No bills this month yet'
                    : 'This month: '.$bills->count().' '.str('bill')->plural($bills->count()).' · '.$money((int) $bills->sum('total_cents'));
            })(),
            default => (function () use ($sources, $weekFrom) {
                $rows = AirtableRecord::query()->whereNull('gone_at')
                    ->where(fn ($q) => $sources->each(fn ($s) => $q->orWhere(fn ($w) => $w->where('base_id', $s->base_id)->where('table_id', $s->table_id))))
                    ->where('record_created_at', '>=', $weekFrom)->count();

                return "This week: {$rows} new ".str('row')->plural($rows);
            })(),
        };
    }

    private static function airtableTables(Collection $sources): array
    {
        if ($sources->isEmpty()) {
            return [];
        }
        $stats = AirtableRecord::query()->whereNull('gone_at')
            ->where(fn ($q) => $sources->unique(fn ($s) => $s->base_id.$s->table_id)->each(fn ($s) => $q->orWhere(fn ($w) => $w->where('base_id', $s->base_id)->where('table_id', $s->table_id))))
            ->selectRaw('base_id, table_id, MAX(table_name) as name, COUNT(*) as n')->groupBy('base_id', 'table_id')->get()
            ->keyBy(fn ($r) => $r->base_id.'/'.$r->table_id);

        // A Financials table that mirrors this Airtable table, when one exists,
        // is what a chip opens; otherwise there is nothing to open yet.
        $mirrors = FinancialTable::query()->whereNotNull('source_table_id')->get()
            ->keyBy(fn ($t) => $t->source_base_id.'/'.$t->source_table_id);

        return $sources->map(fn (FinancialSource $s) => [
            'key' => $s->base_id.'/'.$s->table_id.($s->franchise_id ? '/'.$s->franchise_id : ''),
            'table_id' => $mirrors[$s->base_id.'/'.$s->table_id]->id ?? null,
            'route' => isset($mirrors[$s->base_id.'/'.$s->table_id]) ? '/financials/tables/'.$mirrors[$s->base_id.'/'.$s->table_id]->id : null,
            'name' => trim(($stats[$s->base_id.'/'.$s->table_id]->name ?? $s->table_id).($s->filter_value ? ' · '.$s->filter_value : '')),
            'count' => (int) ($stats[$s->base_id.'/'.$s->table_id]->n ?? 0),
        ])->values()->all();
    }

    private static function refreshed(Collection $sources, ?string $nativeAt): ?string
    {
        $copied = $sources->isEmpty() ? null : AirtableRecord::query()->whereNull('gone_at')
            ->whereIn('table_id', $sources->pluck('table_id'))->max('fetched_at');

        return collect([$copied ? now()->parse($copied)->toIso8601String() : null, $nativeAt])->filter()->max();
    }
}
