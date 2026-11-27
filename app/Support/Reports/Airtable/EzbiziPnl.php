<?php

namespace App\Support\Reports\Airtable;

use App\Models\FinancialSource;
use App\Models\FinancialTable;

/**
 * The P&L rows from EzBizi's Financials copy of the Airtable base, once that
 * base (or the one table) is cut over, so the Profit & Loss screen stops
 * reading Airtable live (plan 2026-11-03, section 6).
 *
 * The rows come back in the Airtable column names (FinancialSource::ezbiziRows),
 * so the same AirtablePnl parser reads them, and its Total / Total Balance
 * checks and disagreement reporting stay exactly as they were.
 *
 * Which store: the one FinancialSource::readingFrom() forced (parity), else
 * EzBizi only for a cut-over table. Null from table() = read Airtable.
 */
final class EzbiziPnl
{
    /** The Financials table holding $tableName of base $baseId, when it is the one to read. */
    public function table(string $baseId, string $tableName): ?FinancialTable
    {
        if (FinancialSource::forcedStore() === 'airtable') {
            return null;
        }

        $table = FinancialTable::query()->where('source_base_id', $baseId)
            ->where('name', $tableName)->orderBy('created_at')->first();

        if ($table === null || (FinancialSource::forcedStore() !== 'ezbizi' && ! $table->isCutOver())) {
            return null;
        }

        return $table;
    }

    /**
     * The base's table names when EzBizi holds every one of them; null when
     * any still follows Airtable, so the picker asks Airtable as before.
     *
     * @return list<string>|null
     */
    public function tables(string $baseId): ?array
    {
        if (FinancialSource::forcedStore() === 'airtable') {
            return null;
        }

        $tables = FinancialTable::query()->where('source_base_id', $baseId)->orderBy('position')->orderBy('name')->get();

        if ($tables->isEmpty()
            || (FinancialSource::forcedStore() !== 'ezbizi' && $tables->contains(fn (FinancialTable $t) => ! $t->isCutOver()))) {
            return null;
        }

        return $tables->map(fn (FinancialTable $t) => (string) $t->name)->values()->all();
    }

    /**
     * Every row of the table, as Airtable column name => value.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(FinancialTable $table): array
    {
        return array_map(fn (array $r) => $r['fields'], FinancialSource::ezbiziRows($table));
    }
}
