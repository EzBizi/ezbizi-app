<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Reports\Airtable\AirtableSnapshot;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Copies A-1's Airtable tables into EzBizi; see AirtableSnapshot. */
class AirtableSnapshotCommand extends Command
{
    protected $signature = 'ezbizi:airtable-snapshot {--organization= : defaults to the one organization with Airtable connected}';

    protected $description = "Copy the Airtable tables the reports read into EzBizi, keeping each changed row's earlier state";

    public function handle(AirtableSnapshot $snapshot, TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Airtable->value)
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', $id))
            ->get();

        if ($connections->pluck('organization_id')->unique()->count() !== 1) {
            $this->warn('Which organization? Pass --organization; more or fewer than one has Airtable connected.');

            return self::FAILURE;
        }

        // One personal access token reaches every base (measured 2026-10-16).
        $token = (string) ($connections->first()->credentials['api_token'] ?? '');
        $organization = Organization::query()->findOrFail($connections->first()->organization_id);

        $done = $tenant->run($organization, fn () => $snapshot->sync((string) $organization->id, $token));

        $this->info("Tables: {$done['tables']}, rows: {$done['rows']}, changed: {$done['changed']}, gone: {$done['gone']}.");
        foreach ($done['failed'] as $line) {
            $this->warn($line);
        }

        return $done['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
