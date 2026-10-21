<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Organization;
use App\Support\Invoices\Invoicing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Raises invoice.overdue once for each sent invoice past its due date with money owed. */
class InvoicesOverdueCommand extends Command
{
    protected $signature = 'ezbizi:invoices-overdue';

    protected $description = 'Announce sent invoices that passed their due date unpaid (once each)';

    public function handle(TenantContext $tenant, Invoicing $invoicing): int
    {
        $orgs = $tenant->runWithoutTenant(fn () => Invoice::query()->where('status', 'sent')->whereNull('overdue_announced_at')->distinct()->pluck('organization_id'));
        foreach (Organization::query()->whereIn('id', $orgs)->get() as $organization) {
            $n = $tenant->run($organization, fn () => $invoicing->sweepOverdue());
            $this->line("{$organization->name}: {$n} overdue");
        }

        return self::SUCCESS;
    }
}
