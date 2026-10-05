<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Support\Customers\ReassignCustomerOwner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Hand every customer a Workiz account knows to the franchise that account
 * belongs to. Written for "A1 - Chimney MA" (2026-10-05): MA's customers were
 * serviced, and imported, through the Nationwide Workiz account, and MA's own
 * account now names who MA serves.
 *
 * Positive provenance: a client on THAT connection, not a state or zip. State
 * would also take customers another branch did the jobs for (Connecticut owns
 * 56 MA customers), and a zip prefix crosses state lines. `--from` narrows the
 * move to named current owners, so a branch nobody ruled on stays put.
 *
 * Each move goes through ReassignCustomerOwner -- its own audit row and its
 * own `customer.owner_reassigned` event -- so check what listens to that event
 * before a real run: one per customer moved.
 */
class RehomeCustomers extends Command
{
    protected $signature = 'ezbizi:customers-rehome
        {--to= : Franchise id that becomes the owner}
        {--via-workiz-connection= : Workiz connection id whose clients name the customers}
        {--from=* : Only move customers owned by these franchise ids}
        {--dry-run : Report the moves and change nothing}';

    protected $description = 'Re-home the customers a Workiz account knows to that account\'s franchise';

    public function handle(TenantContext $tenant, ReassignCustomerOwner $reassign): int
    {
        $to = $tenant->runWithoutTenant(fn () => Franchise::query()->find($this->option('to')));
        $connection = (string) $this->option('via-workiz-connection');
        if ($to === null || $connection === '') {
            $this->error('--to must be a franchise id and --via-workiz-connection is required.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $from = (array) $this->option('from');
        $organization = $tenant->runWithoutTenant(fn () => Organization::query()->find($to->organization_id));

        return $tenant->run($organization, function () use ($to, $connection, $from, $dryRun, $reassign) {
            $candidates = Customer::query()
                ->with('owningFranchise:id,name')
                ->whereIn('id', WorkizClient::query()->where('connection_id', $connection)->whereNotNull('customer_id')->select('customer_id'))
                ->where('owning_franchise_id', '!=', $to->getKey())
                ->get();

            $moving = $from === [] ? $candidates : $candidates->whereIn('owning_franchise_id', $from);

            $this->table(['Current owner', 'Owner id', 'Customers', 'Moves'], $candidates
                ->groupBy('owning_franchise_id')
                ->map(fn ($group, $id) => [
                    $group->first()->owningFranchise?->name, $id, $group->count(),
                    $moving->where('owning_franchise_id', $id)->count(),
                ])->values()->all());

            if (! $dryRun) {
                foreach ($moving as $customer) {
                    $reassign($customer, $to, 'Re-homed to the franchise of Workiz connection '.$connection);
                }
            }

            $this->info(($dryRun ? '[dry run] ' : '')."{$moving->count()} customers "
                .($dryRun ? 'would move' : 'moved')." to {$to->name}.");

            return self::SUCCESS;
        });
    }
}
