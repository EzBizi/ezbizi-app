<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\WorkizClient;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give the customers the Workiz projection made before it wrote
 * `customer_since` the day they first booked: their earliest Workiz job's
 * created date, across every Workiz key they hold (Customers, 2026-10-01).
 *
 * The dashboard counts new customers by this column (b4606536), so without it
 * the ~10,500 rows the projection made read as new on the day they were made.
 *
 * Only rows where it is still NULL: what the A1 import wrote, or a person set,
 * is never overwritten, and a second run finds nothing. `booked_at` is
 * Workiz's CreatedDate as the account wrote it, its own wall clock, so its
 * date part is the branch's local day. One audit row per organization per run.
 */
class BackfillWorkizCustomerSince extends Command
{
    protected $signature = 'ezbizi:workiz-customer-since
        {--dry-run : Count what would be filled and write nothing}';

    protected $description = 'Fill customer_since for Workiz customers from their first Workiz booking';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', WorkizClient::query()->withoutGlobalScopes()->distinct()->pluck('organization_id'))
            ->get();

        foreach ($organizations as $organization) {
            $first = DB::table('workiz_clients as wc')
                ->join('workiz_jobs as wj', fn ($j) => $j->on('wj.franchise_id', '=', 'wc.franchise_id')->on('wj.client_id', '=', 'wc.client_id'))
                ->where('wc.organization_id', $organization->getKey())
                ->whereNotNull('wc.customer_id')
                ->whereNotNull('wj.booked_at')
                ->groupBy('wc.customer_id')
                ->select('wc.customer_id', DB::raw('MIN(DATE(wj.booked_at)) AS first_booked'));

            $missing = DB::table('customers as c')
                ->joinSub($first, 'f', 'f.customer_id', '=', 'c.id')
                ->whereNull('c.customer_since')
                ->whereNull('c.deleted_at');

            $count = $dryRun ? $missing->count() : $missing->update(['c.customer_since' => DB::raw('f.first_booked')]);

            if (! $dryRun && $count > 0) {
                $tenant->run($organization, fn () => $audit->log('customer.since_backfilled', metadata: [
                    'source' => 'workiz',
                    'customers' => $count,
                ]));
            }

            $total += $count;
        }

        $this->info($dryRun
            ? "[dry run] {$total} customers would get the day they first booked in Workiz."
            : "{$total} customers got the day they first booked in Workiz.");

        return self::SUCCESS;
    }
}
