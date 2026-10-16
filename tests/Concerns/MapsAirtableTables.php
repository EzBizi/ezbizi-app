<?php

namespace Tests\Concerns;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;

/**
 * An Airtable table as the reports read it: mapped to a branch in Financials'
 * sources and present in the hourly copy (2026-10-16).
 */
trait MapsAirtableTables
{
    /** @param list<array<string, mixed>> $records the rows' fields */
    protected function airtableTable(array $tenant, ?string $franchiseId, string $role, array $records, array $source = []): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $franchiseId, $role, $records, $source) {
            $base = $source['base_id'] ?? 'appTest0000000001';
            $table = $source['table_id'] ?? 'tbl'.substr(md5($role.$franchiseId), 0, 14);
            FinancialSource::query()->create($source + ['base_id' => $base, 'table_id' => $table, 'role' => $role, 'franchise_id' => $franchiseId]);

            foreach ($records as $i => $fields) {
                AirtableRecord::query()->create(['organization_id' => $tenant['org']->id, 'base_id' => $base, 'table_id' => $table,
                    'table_name' => $role, 'record_id' => "rec{$i}".substr(md5($table), 0, 8), 'fields' => $fields,
                    'fields_hash' => md5((string) json_encode($fields)), 'fetched_at' => now()]);
            }
        });
    }
}
