<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\FinancialTable;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

final class AirtableTablesCounter extends RowCounter
{
    protected function key(): string
    {
        return 'airtable_tables';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->provider === 'airtable' && $ref->lastOfProvider;
    }

    protected function query(IntegrationRef $ref): Builder
    {
        return FinancialTable::query()->whereNotNull('source_base_id');
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'Financials table stops syncing from Airtable', 'Financials tables stop syncing from Airtable');
    }

    public function features(IntegrationRef $ref): array
    {
        return $ref->provider === 'airtable'
            ? [Feature::lastBreaks($ref, 'Reports › Financials: Airtable tables stop syncing; what was imported stays')]
            : [];
    }
}
