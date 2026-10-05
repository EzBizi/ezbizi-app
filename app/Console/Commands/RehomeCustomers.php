<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Support\Customers\ReassignCustomerOwner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
 * `--state` exists because MA's Workiz account turned out to hold 64 clients,
 * not the ~800 MA customers Nationwide's account brought in: a state is the
 * only rule that reaches the rest. Pair it with `--from`, always.
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
        {--state=* : Also take customers whose state is one of these (e.g. MA)}
        {--from=* : Only move customers owned by these franchise ids}
        {--dry-run : Report the moves and change nothing}';

    protected $description = 'Re-home the customers a Workiz account knows to that account\'s franchise';

    public function handle(TenantContext $tenant, ReassignCustomerOwner $reassign): int
    {
        $to = $tenant->runWithoutTenant(fn () => Franchise::query()->find($this->option('to')));
        $connection = (string) $this->option('via-workiz-connection');
        $states = array_map(fn ($s) => strtoupper(trim($s)), (array) $this->option('state'));
        if ($to === null || ($connection === '' && $states === [])) {
            $this->error('--to must be a franchise id, and --via-workiz-connection or --state names the customers.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $from = (array) $this->option('from');
        $organization = $tenant->runWithoutTenant(fn () => Organization::query()->find($to->organization_id));

        return $tenant->run($organization, function () use ($to, $connection, $states, $from, $dryRun, $reassign) {
            $candidates = Customer::query()
                ->with('owningFranchise:id,name')
                ->where(fn ($q) => $q
                    ->when($connection !== '', fn ($w) => $w->orWhereIn('id', WorkizClient::query()
                        ->where('connection_id', $connection)->whereNotNull('customer_id')->select('customer_id')))
                    ->when($states !== [], fn ($w) => $w->orWhereIn(DB::raw('UPPER(TRIM(state))'), $states)))
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
                    $reassign($customer, $to, 'Re-homed to '.$to->name.($connection !== '' ? ' via Workiz connection '.$connection : '').($states !== [] ? ' by state '.implode(',', $states) : ''));
                }
            }

            $this->info(($dryRun ? '[dry run] ' : '')."{$moving->count()} customers "
                .($dryRun ? 'would move' : 'moved')." to {$to->name}.");

            return self::SUCCESS;
        });
    }
}
