<?php

namespace App\Console\Commands;

use App\Models\FinancialSource;
use App\Models\Organization;
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
        $orgs = FinancialSource::query()->withoutGlobalScopes()
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', $id))
            ->distinct()->pluck('organization_id');

        $ok = true;
        foreach ($orgs as $orgId) {
            // One connection per base (remote_id is the base id): each base is
            // read with its own connection's token, not the first row's.
            $tokens = SocialConnection::query()->withoutGlobalScopes()->where('organization_id', $orgId)
                ->where('provider', SocialProvider::Airtable->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)->get()
                ->mapWithKeys(fn (SocialConnection $c) => [(string) $c->remote_id => (string) ($c->credentials['api_token'] ?? '')])
                ->filter()->all();
            $organization = Organization::query()->find($orgId);

            if ($tokens === [] || $organization === null) {
                $this->warn("{$orgId}: tables are mapped but Airtable is not connected.");
                $ok = false;

                continue;
            }

            $done = $tenant->run($organization, fn () => $snapshot->sync((string) $orgId, $tokens));

            $this->info("{$organization->name}: tables {$done['tables']}, rows {$done['rows']}, changed {$done['changed']}, gone {$done['gone']}.");
            foreach ($done['failed'] as $line) {
                $this->warn($line);
                $ok = false;
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
