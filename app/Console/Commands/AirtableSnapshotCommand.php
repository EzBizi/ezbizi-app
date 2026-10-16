<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ReportSource;
use App\Models\SocialConnection;
use App\Support\Reports\Airtable\AirtableSnapshot;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Copies each organization's mapped Airtable tables into EzBizi; see AirtableSnapshot. */
class AirtableSnapshotCommand extends Command
{
    protected $signature = 'ezbizi:airtable-snapshot {--organization= : one organization; defaults to every one with tables mapped}';

    protected $description = "Copy the Airtable tables each organization mapped into its reports, keeping each changed row's earlier state";

    public function handle(AirtableSnapshot $snapshot, TenantContext $tenant): int
    {
        $orgs = ReportSource::query()->withoutGlobalScopes()
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', $id))
            ->distinct()->pluck('organization_id');

        $ok = true;
        foreach ($orgs as $orgId) {
            // An Airtable personal access token reaches every base it was
            // granted, so the organization's connection is the credential.
            $token = (string) (SocialConnection::query()->withoutGlobalScopes()->where('organization_id', $orgId)
                ->where('provider', SocialProvider::Airtable->value)->first()?->credentials['api_token'] ?? '');
            $organization = Organization::query()->find($orgId);

            if ($token === '' || $organization === null) {
                $this->warn("{$orgId}: tables are mapped but Airtable is not connected.");
                $ok = false;

                continue;
            }

            $done = $tenant->run($organization, fn () => $snapshot->sync((string) $orgId, $token));

            $this->info("{$organization->name}: tables {$done['tables']}, rows {$done['rows']}, changed {$done['changed']}, gone {$done['gone']}.");
            foreach ($done['failed'] as $line) {
                $this->warn($line);
                $ok = false;
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
