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
 *  - anything a PERSON made or edited — the API audits every job and customer
 *    it creates (`job.created`, `customer.created`) and every edit, and the
 *    projection audits none of them per record;
 *  - anything without a LINKED twin — an unlinked record with no twin may be
 *    the only copy;
 *  - anything something hangs off — an attachment, or any row in any table
 *    that names it — because that means somebody used it;
 *  - a customer who still has jobs.
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
        $jobTables = $this->tablesWith('job_id', ['workiz_jobs']);
        $customerTables = $this->tablesWith('customer_id', ['workiz_clients', 'jobs']);

        $jobs = 0;
        $jobsKept = 0;

        $orphanJobs = Job::query()
            ->whereNotExists(fn ($q) => $q->from('workiz_jobs')->whereColumn('workiz_jobs.job_id', 'jobs.id'))
            ->whereNotExists(fn ($q) => $q->from('audit_events')
                ->whereColumn('audit_events.target_id', 'jobs.id')
                ->where('audit_events.action', 'like', 'job.%'))
            ->get();

        foreach ($orphanJobs as $orphan) {
            $twin = Job::query()
                ->whereKeyNot($orphan->getKey())
                ->where('customer_id', $orphan->customer_id)
                ->where('franchise_id', $orphan->franchise_id)
                ->where('title', $orphan->title)
                ->whereRaw('scheduled_for <=> ?', [$orphan->getRawOriginal('scheduled_for')])
                ->whereExists(fn ($q) => $q->from('workiz_jobs')->whereColumn('workiz_jobs.job_id', 'jobs.id'))
                ->exists();

            if (! $twin || $this->isUsed($orphan->getKey(), 'job_id', $jobTables)) {
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

        $orphanCustomers = Customer::query()
            ->whereNotExists(fn ($q) => $q->from('workiz_clients')->whereColumn('workiz_clients.customer_id', 'customers.id'))
            ->whereNotExists(fn ($q) => $q->from('audit_events')
                ->whereColumn('audit_events.target_id', 'customers.id')
                ->where('audit_events.action', 'like', 'customer.%'))
            ->whereNotExists(fn ($q) => $q->from('jobs')
                ->whereColumn('jobs.customer_id', 'customers.id')
                ->whereNull('jobs.deleted_at'))
            ->get();

        foreach ($orphanCustomers as $orphan) {
            $twin = Customer::query()
                ->whereKeyNot($orphan->getKey())
                ->where('owning_franchise_id', $orphan->owning_franchise_id)
                ->where('name', $orphan->name)
                ->whereRaw('phone <=> ?', [$orphan->phone])
                ->whereExists(fn ($q) => $q->from('workiz_clients')->whereColumn('workiz_clients.customer_id', 'customers.id'))
                ->exists();

            if (! $twin || $this->isUsed($orphan->getKey(), 'customer_id', $customerTables)) {
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
     * Anything that names this record — an attachment, or a row in any table
     * with the column — means somebody used it.
     *
     * Attachments are matched on the id alone rather than the morph type, so a
     * morph alias cannot make one invisible; ids are UUIDs and never collide.
     *
     * @param  list<string>  $tables
     */
    private function isUsed(string $id, string $column, array $tables): bool
    {
        if (DB::table('attachments')->where('attachable_id', $id)->exists()) {
            return true;
        }

        foreach ($tables as $table) {
            if (DB::table($table)->where($column, $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every table carrying the column, found in the schema rather than listed,
     * because a list goes stale the day somebody adds a table that points at
     * a job — and would then remove a record something still hangs off.
     *
     * @param  list<string>  $except
     * @return list<string>
     */
    private function tablesWith(string $column, array $except): array
    {
        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('COLUMN_NAME', $column)
            ->whereNotIn('TABLE_NAME', $except)
            ->pluck('TABLE_NAME')
            ->all();
    }
}
