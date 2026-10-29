<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\ThumbtackLeads;
use App\Support\Social\ThumbtackNegotiations;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * What each Thumbtack lead cost, and whether it was refunded, for the
 * Thumbtack financials in Reports (Jose, 2026-10-13). Thumbtack cannot list
 * negotiations, so this re-reads the ones EzBizi received: any with no price
 * yet, and any from the last 60 days not yet credited, so a refund shows.
 */
class ThumbtackLeadCosts extends Command
{
    protected $signature = 'ezbizi:thumbtack-lead-costs {--all : every Thumbtack lead, however old}';

    protected $description = 'Store each Thumbtack lead\'s price and charge state from Thumbtack';

    public function handle(TenantContext $tenant): int
    {
        $leads = Lead::query()->withoutGlobalScopes()
            ->where('source', Lead::SOURCE_THUMBTACK)
            ->whereNotNull('connection_id')->whereNotNull('remote_id')
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('lead_cost_minor')
                ->orWhere(fn ($q) => $q->where('received_at', '>=', now()->subDays(60))
                    ->where(fn ($q) => $q->whereNull('charge_status')->orWhere('charge_status', '!=', 'CREDITED')))))
            ->get();

        $done = 0;
        foreach ($leads->groupBy('organization_id') as $orgId => $group) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($orgId), function () use ($group, &$done) {
                foreach ($group as $lead) {
                    $connection = SocialConnection::query()->find($lead->connection_id);
                    if ($connection === null) {
                        continue;
                    }
                    try {
                        $raw = ThumbtackNegotiations::for($connection)->find((string) $lead->remote_id);
                    } catch (Throwable $e) {
                        $this->warn("{$lead->remote_id}: {$e->getMessage()}");

                        continue;
                    }
                    $lead->forceFill(array_filter(ThumbtackLeads::cost($raw), fn ($v) => $v !== null) + ThumbtackLeads::details($raw))->save();
                    $done++;
                }
            });
        }

        $this->info("Read {$done} of {$leads->count()} Thumbtack lead(s).");

        return self::SUCCESS;
    }
}
