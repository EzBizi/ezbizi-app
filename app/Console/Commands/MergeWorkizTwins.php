<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Fold the twins the old-jobs backfill made back into the customer they are.
 *
 * ── WHAT HAPPENED ───────────────────────────────────────────────
 *
 * The backfill (2026-09-29, 18:44 to 21:17 UTC) landed old jobs whose Workiz
 * client numbers EzBizi did not hold, and the projection, which never merges,
 * created a customer for each. 696 of those share a phone and a name with an
 * older live customer: Workiz's own duplicate client records (270 in the same
 * franchise) and keys the A1 export dropped as other people (426 in another).
 *
 * ── WHAT IT DOES, AND ONLY WITH CUSTOMERS' AND JOSE'S WORD ─────────
 *
 * A twin is a customer made inside the window, holding a Workiz key, whose
 * phone and name (case and spacing ignored) match exactly ONE live customer
 * made before it. Its jobs and its Workiz keys move to that customer, and it is
 * soft-deleted: one transaction per pair, under the projection's lock, every
 * move in a manifest and every pair in one audit row, so a wrong one can be
 * undone. Customers approved the same-franchise pairs (2026-09-29). A pair in
 * another franchise also moves a branch's numbers, which is Jose's call:
 * `--cross-franchise` includes them, and nothing else does.
 *
 * ── WHAT IT WILL NOT TOUCH, CHECKED WHEN IT RUNS ─────────────────
 *
 * Customers' condition: the reading that none was edited was true when taken,
 * and this runs later. So a twin is left alone, and reported, when:
 *
 *  - it matches several older customers: listed for Customers, one at a time;
 *  - a person changed it: `updated_at` is later than it was made, since the
 *    projection writes a customer once and never again;
 *  - anything names it apart from its jobs and Workiz keys: any `…_id` column in
 *    any table, the scan RemoveWorkizDuplicates uses. That covers an audit row
 *    about it, which only a person's edit writes, and anything else that would
 *    be left pointing at a deleted customer.
 */
class MergeWorkizTwins extends Command
{
    protected $signature = 'ezbizi:workiz-merge-twins
        {from : Twins were made at or after this moment, UTC (Y-m-d H:i:s)}
        {to : ... and at or before this one}
        {--cross-franchise : Also fold twins owned by another franchise (Jose\'s call)}
        {--dry-run : Report what would be merged and change nothing}
        {--wait=120 : Seconds to wait for a running Workiz projection}';

    protected $description = 'Fold the customer twins the Workiz backfill made back into the customer they are';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $window = [(string) $this->argument('from'), (string) $this->argument('to')];

        foreach ($window as $moment) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $moment)) {
                $this->error('from and to are UTC moments, Y-m-d H:i:s.');

                return self::FAILURE;
            }
        }

        $lock = Cache::lock(ProjectWorkizRecords::LOCK, 3600);

        try {
            $lock->block((int) $this->option('wait'));
        } catch (LockTimeoutException) {
            $this->error('The Workiz projection is running. Nothing was changed; run this again.');

            return self::FAILURE;
        }

        try {
            $organizations = Organization::query()->withoutGlobalScopes()
                // Every twin holds a Workiz key, so these are the only places one can be.
                ->whereIn('id', WorkizClient::query()->withoutGlobalScopes()->distinct()->pluck('organization_id'))
                ->get();

            foreach ($organizations as $organization) {
                $tenant->run($organization, fn () => $this->mergeIn($window, $audit));
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function mergeIn(array $window, AuditLogger $audit): void
    {
        $dryRun = (bool) $this->option('dry-run');
        $crossFranchise = (bool) $this->option('cross-franchise');
        $norm = fn (?string $name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));

        $older = [];

        foreach (Customer::query()->whereNotNull('phone')->where('created_at', '<', $window[0])
            ->get(['id', 'phone', 'name', 'owning_franchise_id']) as $customer) {
            $older[$customer->phone.'|'.$norm($customer->name)][] = $customer;
        }

        $twins = Customer::query()
            ->whereNotNull('phone')
            ->whereBetween('created_at', $window)
            ->whereExists(fn ($q) => $q->from('workiz_clients')->whereColumn('workiz_clients.customer_id', 'customers.id'))
            ->get();

        $named = $this->namedElsewhere($twins->modelKeys());
        $pairs = [];
        $report = ['several' => [], 'touched' => [], 'named' => [], 'held' => 0];

        foreach ($twins as $twin) {
            $matches = $older[$twin->phone.'|'.$norm($twin->name)] ?? [];

            if ($matches === []) {
                continue;
            }

            if (count($matches) > 1) {
                $report['several'][] = $twin->getKey().' matches '.implode(', ', array_map(fn ($c) => $c->getKey(), $matches));
            } elseif ($twin->updated_at->gt($twin->created_at)) {
                $report['touched'][] = $twin->getKey();
            } elseif (isset($named[$twin->getKey()])) {
                $report['named'][] = $twin->getKey();
            } elseif ($matches[0]->owning_franchise_id !== $twin->owning_franchise_id && ! $crossFranchise) {
                $report['held']++;
            } else {
                $pairs[] = [$twin, $matches[0]];
            }
        }

        $this->info(sprintf(
            '%s%d twins: %d to merge%s, %d in another franchise held for Jose, %d match several customers, %d changed by a person, %d named by something else.',
            $dryRun ? '[dry run] ' : '', count($pairs) + $report['held'] + count($report['several']) + count($report['touched']) + count($report['named']),
            count($pairs), $crossFranchise ? ' (other franchises included)' : '', $report['held'],
            count($report['several']), count($report['touched']), count($report['named']),
        ));

        foreach (['several', 'touched', 'named'] as $kind) {
            foreach ($report[$kind] as $line) {
                $this->line("  {$kind}: {$line}");
            }
        }

        if ($dryRun || $pairs === []) {
            return;
        }

        $path = 'workiz-twins/merged-'.now()->format('Ymd-His').'.jsonl';
        Storage::disk('local')->makeDirectory('workiz-twins');
        $manifest = fopen(Storage::disk('local')->path($path), 'a');
        $listed = [];

        try {
            foreach ($pairs as [$twin, $keep]) {
                $moved = DB::transaction(function () use ($twin, $keep) {
                    $jobs = Job::withTrashed()->where('customer_id', $twin->getKey())->pluck('id')->all();
                    $keys = WorkizClient::query()->where('customer_id', $twin->getKey())->pluck('id')->all();

                    Job::withTrashed()->whereKey($jobs)->update(['customer_id' => $keep->getKey()]);
                    WorkizClient::query()->whereKey($keys)->update(['customer_id' => $keep->getKey()]);
                    $twin->delete();

                    return ['jobs' => $jobs, 'workiz_clients' => $keys];
                });

                $line = ['twin' => $twin->getKey(), 'kept' => $keep->getKey()] + $moved;
                fwrite($manifest, json_encode($line)."\n");
                $listed[] = ['twin' => $twin->getKey(), 'kept' => $keep->getKey(), 'jobs' => count($moved['jobs'])];
            }
        } finally {
            fclose($manifest);
        }

        $audit->log('customer.twins_merged', metadata: [
            'source' => 'workiz backfill',
            'window' => $window,
            'cross_franchise' => $crossFranchise,
            'merged' => $listed,
            'manifest' => $path,
        ]);

        $this->info('Merged '.count($listed).". Manifest: {$path} on the local disk.");
    }

    /**
     * Which of these customers some row names apart from their own jobs and
     * Workiz keys, which this moves: every `…_id` column in the schema, deleted
     * rows included (RemoveWorkizDuplicates::named, less the two it re-points).
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function namedElsewhere(array $ids): array
    {
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('COLUMN_NAME', 'like', '%\_id')
            ->whereIn('DATA_TYPE', ['char', 'varchar'])
            ->get(['TABLE_NAME', 'COLUMN_NAME'])
            ->reject(fn ($c) => in_array($c->TABLE_NAME.'.'.$c->COLUMN_NAME, ['jobs.customer_id', 'workiz_clients.customer_id'], true));

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
