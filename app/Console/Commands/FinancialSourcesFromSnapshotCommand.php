<?php

namespace App\Console\Commands;

use App\Models\FinancialSource;
use App\Models\Organization;
use App\Support\Reports\Airtable\AirtableSnapshot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * One-off: turns the Airtable table list Reports shipped as a constant into
 * that organization's own Financials sources, so the constant can go. Rows that
 * already exist are left alone; companies and column maps are set in Settings.
 */
class FinancialSourcesFromSnapshotCommand extends Command
{
    protected $signature = 'ezbizi:financial-sources-from-snapshot {--organization= : the organization those tables belong to}';

    protected $description = "Write the Airtable copy's built-in table list as one organization's Financials sources";

    public function handle(TenantContext $tenant): int
    {
        $organization = Organization::query()->find($this->option('organization'));
        if ($organization === null) {
            $this->error('Pass --organization=<id>.');

            return self::FAILURE;
        }

        $added = $tenant->run($organization, function () {
            $added = 0;
            foreach (AirtableSnapshot::TABLES as $baseId => $tables) {
                foreach ($tables as $tableId => $role) {
                    $added += (int) FinancialSource::query()->firstOrCreate(
                        ['base_id' => $baseId, 'table_id' => $tableId, 'franchise_id' => null],
                        ['role' => $role],
                    )->wasRecentlyCreated;
                }
            }

            return $added;
        });

        $this->info("{$organization->name}: {$added} sources added.");

        return self::SUCCESS;
    }
}
