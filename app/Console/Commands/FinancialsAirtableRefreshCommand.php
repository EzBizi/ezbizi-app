<?php

namespace App\Console\Commands;

use App\Models\AirtableRecord;
use App\Models\FinancialTable;
use App\Models\Organization;
use App\Support\Financials\AirtableMirror;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Refreshes every Financials table that mirrors the Airtable copy. Runs after ezbizi:airtable-snapshot. */
class FinancialsAirtableRefreshCommand extends Command
{
    protected $signature = 'ezbizi:financials-airtable-refresh';

    protected $description = 'Bring every Financials table linked to Airtable up to date with the hourly Airtable copy';

    public function handle(TenantContext $tenant): int
    {
        // Every organization with an Airtable copy: its tables all mirror.
        $organizations = $tenant->runWithoutTenant(fn () => AirtableRecord::query()
            ->distinct()->pluck('organization_id'));

        foreach (Organization::query()->whereIn('id', $organizations)->get() as $organization) {
            $tenant->run($organization, function () use ($organization) {
                foreach (AirtableMirror::linkAll() as $table) {
                    $this->line("{$organization->name} / {$table->name}: now mirrored");
                }
                foreach (FinancialTable::query()->whereNotNull('source_table_id')->get() as $table) {
                    $done = AirtableMirror::refresh($table);
                    $this->line("{$organization->name} / {$table->name}: +{$done['added']} ~{$done['changed']} -{$done['removed']}");
                }
            });
        }

        return self::SUCCESS;
    }
}
