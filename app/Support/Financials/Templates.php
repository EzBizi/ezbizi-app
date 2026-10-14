<?php

namespace App\Support\Financials;

use App\Models\FinancialBase;
use App\Models\FinancialTable;

/** Starter bases, like Airtable's templates. */
final class Templates
{
    private const COLOURS = ['Income' => 'green', 'Expense' => 'red'];

    public const ALL = [
        'blank' => 'Blank',
        'bookkeeping' => 'Bookkeeping',
        'budget' => 'Budget',
        'invoices' => 'Invoice tracker',
    ];

    public static function build(FinancialBase $base, string $template): void
    {
        match ($template) {
            'bookkeeping' => self::table($base, 'Transactions', [
                ['Date', 'date'],
                ['Type', 'single_select', ['choices' => self::choices(['Income', 'Expense'])]],
                ['Category', 'single_select', ['choices' => self::choices(['Sales', 'Materials', 'Fuel', 'Payroll', 'Rent', 'Marketing', 'Insurance', 'Other'])]],
                ['Amount', 'currency', ['symbol' => '$', 'precision' => 2]],
                ['Paid', 'checkbox'],
                ['Notes', 'long_text'],
            ], kanbanBy: 'Category', calendarBy: 'Date'),
            'budget' => self::table($base, 'Budget', [
                ['Category', 'single_select', ['choices' => self::choices(['Payroll', 'Materials', 'Fuel', 'Marketing', 'Rent', 'Other'])]],
                ['Budgeted', 'currency', ['symbol' => '$', 'precision' => 2]],
                ['Actual', 'currency', ['symbol' => '$', 'precision' => 2]],
                ['Remaining', 'formula', ['formula' => '{Budgeted} - {Actual}']],
                ['Month', 'date'],
            ], kanbanBy: 'Category', calendarBy: 'Month'),
            'invoices' => self::table($base, 'Invoices', [
                ['Customer', 'text'],
                ['Issued', 'date'],
                ['Due', 'date'],
                ['Amount', 'currency', ['symbol' => '$', 'precision' => 2]],
                ['Status', 'single_select', ['choices' => self::choices(['Draft', 'Sent', 'Paid', 'Overdue'])]],
            ], kanbanBy: 'Status', calendarBy: 'Due'),
            default => self::blankTable($base, 'Table 1', 0),
        };
    }

    public static function blankTable(FinancialBase $base, string $name, int $position): FinancialTable
    {
        return self::table($base, $name, [['Notes', 'long_text']], position: $position);
    }

    /** @param list<array{0:string,1:string,2?:array}> $fields */
    private static function table(FinancialBase $base, string $name, array $fields, ?string $kanbanBy = null, ?string $calendarBy = null, int $position = 0): FinancialTable
    {
        $table = FinancialTable::create(['base_id' => $base->id, 'name' => $name, 'position' => $position]);
        $table->fields()->create(['name' => 'Name', 'type' => 'text', 'is_primary' => true, 'position' => 0]);

        $ids = [];
        foreach ($fields as $i => $f) {
            $ids[$f[0]] = $table->fields()->create(['name' => $f[0], 'type' => $f[1], 'options' => $f[2] ?? null, 'position' => $i + 1])->id;
        }

        $table->views()->create(['name' => 'Grid view', 'type' => 'grid', 'position' => 0]);
        if ($kanbanBy !== null) {
            $table->views()->create(['name' => 'By '.strtolower($kanbanBy), 'type' => 'kanban', 'config' => ['group_field' => $ids[$kanbanBy]], 'position' => 1]);
        }
        if ($calendarBy !== null) {
            $table->views()->create(['name' => 'Calendar', 'type' => 'calendar', 'config' => ['date_field' => $ids[$calendarBy]], 'position' => 2]);
        }

        return $table;
    }

    private static function choices(array $names): array
    {
        return array_map(fn ($n) => ['name' => $n, 'colour' => self::COLOURS[$n] ?? null], $names);
    }
}
