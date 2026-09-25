<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizJob;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Remove the duplicate jobs and customers the 2026-09-23 projection race left.
 *
 * ── WHAT HAPPENED ───────────────────────────────────────────────
 *
 * At 20:37 that day the scheduled projection and a manual one overlapped — the
 * schedule's withoutOverlapping() guards only scheduled invocations, and the
 * projection took no lock of its own. Both read the same unprojected rows and
 * both created a record for each; the link written last won, and the other
 * copy was orphaned. Measured on production: 432 jobs and 500 customers with
 * no mirror row pointing at them, EVERY ONE with a surviving linked twin and
 * nothing attached. The Jobs session found them as twins; the cause is fixed
 * in ProjectWorkizRecords by a lock this command also takes.
 *
 * ── WHAT IT WILL NOT TOUCH ──────────────────────────────────────
 *
 * A deletion is the one mistake here nobody sees afterwards, so every rule is
 * about what to leave:
 *
 *  - anything created outside the race's two minutes (RACE). "Unlinked and
 *    unreferenced" is an absence; this is the positive fact. It is the Customers
 *    session's condition: a customer imported by CSV has no per-record audit
 *    row and nothing naming it, so a CSV copy of a Workiz client would
 *    otherwise pass every other rule;
 *  - anything without a LINKED twin — an unlinked record with no twin may be
 *    the only copy;
 *  - anything ANY row in ANY table names — a job pointing at a customer (even a
 *    deleted job), an attachment, a notification, an automation event, an
 *    audit entry. The last one is what protects what a PERSON made or edited:
 *    the API audits every job and customer it creates and every edit, and the
 *    projection audits none of them per record.
 *
 * On production on 2026-09-24 nothing, in any of 438 id columns or 138 JSON and
 * text columns, named any of the 432 jobs or 500 customers, and no CSV import
 * had ever run in an organization with Workiz.
 *
 * A job's twin is the same customer, branch, title and appointment. NOT price
 * or status: 0.0.0264's follow pass had already moved those on ten linked
 * copies and left their unlinked duplicates frozen.
 *
 * Soft-deleted, so reversible. One audit row per run.
 */
class RemoveWorkizDuplicates extends Command
{
    protected $signature = 'ezbizi:workiz-remove-duplicates
        {--dry-run : Report what would be removed and remove nothing}';

    protected $description = 'Remove the duplicate jobs and customers the 2026-09-23 Workiz projection race left behind';

    /**
     * The overlapping runs' own minutes, UTC. Measured on production: the 500
     * customers were created 20:36:57 to 20:37:10 and the 432 jobs 20:37:12 to
     * 20:37:29, around the two runs' `customer.imported` audit rows (20:37:10,
     * limit 500; 20:37:12, limit 10000). The runs before and after wrote theirs
     * at 20:31:14 and 20:42:09.
     */
    private const RACE = ['2026-09-23 20:36:00', '2026-09-23 20:38:00'];

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        // The projection's own lock, so it cannot be half-way through linking
        // a row that this would read as an orphan.
        $lock = Cache::lock(ProjectWorkizRecords::LOCK, 3600);

        if (! $lock->get()) {
            $this->info('A Workiz projection is running; try again when it has finished.');

            return self::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            $organizations = Organization::query()
                ->withoutGlobalScopes()
                ->whereIn('id', WorkizJob::query()->withoutGlobalScopes()->distinct()->pluck('organization_id'))
                ->get();

            $totals = ['jobs' => 0, 'jobs_kept' => 0, 'customers' => 0, 'customers_kept' => 0];

            foreach ($organizations as $organization) {
                $result = $tenant->run($organization, fn () => $this->removeIn($audit, $dryRun));

                foreach ($result as $key => $n) {
                    $totals[$key] += $n;
                }
            }

            $verb = $dryRun ? 'would be removed' : 'removed';
            $this->info(($dryRun ? '[dry run] ' : '').$totals['jobs'].' duplicate jobs '.$verb.', '.$totals['jobs_kept'].' unlinked jobs kept.');
            $this->info(($dryRun ? '[dry run] ' : '').$totals['customers'].' duplicate customers '.$verb.', '.$totals['customers_kept'].' unlinked customers kept.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{jobs: int, jobs_kept: int, customers: int, customers_kept: int}
     */
    private function removeIn(AuditLogger $audit, bool $dryRun): array
    {
        $jobs = 0;
        $jobsKept = 0;

        $orphanJobs = Job::query()
            ->whereBetween('created_at', self::RACE)
            ->whereNotExists(fn ($q) => $q->from('workiz_jobs')->whereColumn('workiz_jobs.job_id', 'jobs.id'))
            ->get();
        $named = $this->named($orphanJobs->modelKeys());

        foreach ($orphanJobs as $orphan) {
            $twin = Job::query()
                ->whereKeyNot($orphan->getKey())
                ->where('customer_id', $orphan->customer_id)
                ->where('franchise_id', $orphan->franchise_id)
                ->where('title', $orphan->title)
                ->whereRaw('scheduled_for <=> ?', [$orphan->getRawOriginal('scheduled_for')])
                ->whereExists(fn ($q) => $q->from('workiz_jobs')->whereColumn('workiz_jobs.job_id', 'jobs.id'))
                ->exists();

            if (! $twin || isset($named[$orphan->getKey()])) {
                $jobsKept++;

                continue;
            }

            if (! $dryRun) {
                $orphan->delete();
            }

            $jobs++;
        }

        $customers = 0;
        $customersKept = 0;

        // After the jobs, so a job just removed still names its customer: the
        // scan reads deleted rows too, and a customer anything points at stays.
        $orphanCustomers = Customer::query()
            ->whereBetween('created_at', self::RACE)
            ->whereNotExists(fn ($q) => $q->from('workiz_clients')->whereColumn('workiz_clients.customer_id', 'customers.id'))
            ->get();
        $named = $this->named($orphanCustomers->modelKeys());

        foreach ($orphanCustomers as $orphan) {
            $twin = Customer::query()
                ->whereKeyNot($orphan->getKey())
                ->where('owning_franchise_id', $orphan->owning_franchise_id)
                ->where('name', $orphan->name)
                ->whereRaw('phone <=> ?', [$orphan->phone])
                ->whereExists(fn ($q) => $q->from('workiz_clients')->whereColumn('workiz_clients.customer_id', 'customers.id'))
                ->exists();

            if (! $twin || isset($named[$orphan->getKey()])) {
                $customersKept++;

                continue;
            }

            if (! $dryRun) {
                $orphan->delete();
            }

            $customers++;
        }

        if (! $dryRun && ($jobs + $customers) > 0) {
            $audit->log('workiz.duplicates_removed', metadata: [
                'jobs' => $jobs,
                'customers' => $customers,
                'reason' => 'Duplicates from the 2026-09-23 overlapping projection runs; every one had a linked twin.',
            ]);
        }

        return ['jobs' => $jobs, 'jobs_kept' => $jobsKept, 'customers' => $customers, 'customers_kept' => $customersKept];
    }

    /**
     * Which of these ids some row, in any table, names in any `…_id` column.
     *
     * Every column comes from the schema, not a list, and is matched on the id
     * alone, not on a column name or morph type: ids are UUIDs and never
     * collide, so a morph alias (`attachments` says `jobs`) cannot hide a
     * reference, and the next table to point at a job — a foreign key or a
     * morph pair, whatever it is called — protects that job the day it exists.
     * Deleted rows count too. On production the whole scan takes seconds.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function named(array $ids): array
    {
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('COLUMN_NAME', 'like', '%\_id')
            ->whereIn('DATA_TYPE', ['char', 'varchar'])
            ->get(['TABLE_NAME', 'COLUMN_NAME']);

        $named = [];

        foreach ($columns as $column) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::table($column->TABLE_NAME)->whereIn($column->COLUMN_NAME, $chunk)->distinct()->pluck($column->COLUMN_NAME) as $id) {
                    $named[$id] = true;
                }
            }
        }

        return $named;
    }
}
