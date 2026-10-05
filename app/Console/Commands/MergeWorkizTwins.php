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
 * ── WHAT IT DOES ─────────────────────────────────────────────────
 *
 * A twin is a customer made inside the window, holding a Workiz key, whose
 * phone and name (case and spacing ignored) match exactly ONE live customer
 * made before it at the SAME POSTAL CODE, in any franchise. Its jobs and its
 * Workiz keys move to that customer, and it is soft-deleted: one transaction
 * per pair, under the projection's lock, every move in a manifest and every
 * pair in one audit row, so a wrong one can be undone. Customers approved
 * these (2026-09-29).
 *
 * Any franchise, because plan §5.2 (DECIDED) makes one person served by two
 * branches ONE customer: "duplicates create two half-histories for one
 * relationship". The kept customer keeps its own owning franchise, the older
 * one, which was accountable first; every job keeps its own franchise, so no
 * branch's work moves, only which record it points at.
 *
 * When two older records of the person share the address -- themselves twins,
 * from before -- the one in the twin's own branch is the one kept, if there is
 * exactly one; otherwise it is left and listed (Customers' ruling on the six).
 *
 * The postal code is Customers' condition, from reading the six ambiguous
 * groups on the box: staff and agents book jobs under their OWN name and phone
 * for other people's houses, so phone and name alone call two households one
 * person. A real Workiz twin of one person shares one address; a booker's
 * houses do not. (It does not separate a Sr from a Jr at one address; nothing
 * here can.)
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
        {--dry-run : Report what would be merged and change nothing}
        {--wait=120 : Seconds to wait for a running Workiz projection}';

    protected $description = 'Fold the customer twins the Workiz backfill made back into the customer they are';

    /**
     * Street lines that are nobody's house: the placeholder A-1's test records
     * and staff bookers sit on, across franchises (Customers, 2026-09-29). Two
     * records meeting there are two junk records, not a person's twins, and
     * whether they stay at all is Jose's to decide. The postal guard cannot see
     * them: they all share 33009. Compared normalised, see street().
     */
    public const PLACEHOLDER_STREETS = ['800 se 4th ave'];

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
        $norm = fn (?string $name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));

        // Who the twin is (phone and name), and who of those lives there too.
        $person = [];
        $there = [];

        foreach (Customer::query()->whereNotNull('phone')->where('created_at', '<', $window[0])
            ->get(['id', 'phone', 'name', 'owning_franchise_id', 'postal_code', 'address_line1']) as $customer) {
            $key = $customer->phone.'|'.$norm($customer->name);
            $person[$key] = true;

            if ($customer->postal_code !== null) {
                $there[$key.'|'.$customer->postal_code][] = $customer;
            }
        }

        $twins = Customer::query()
            ->whereNotNull('phone')
            ->whereBetween('created_at', $window)
            ->whereExists(fn ($q) => $q->from('workiz_clients')->whereColumn('workiz_clients.customer_id', 'customers.id'))
            ->get();

        $named = self::namedElsewhere($twins->modelKeys(), ['jobs.customer_id', 'workiz_clients.customer_id']);
        $pairs = [];
        $report = ['several' => [], 'address' => 0, 'placeholder' => 0, 'touched' => [], 'named' => []];

        foreach ($twins as $twin) {
            $key = $twin->phone.'|'.$norm($twin->name);

            if (! isset($person[$key])) {
                continue;
            }

            $matches = $twin->postal_code === null ? [] : ($there[$key.'|'.$twin->postal_code] ?? []);

            if (count($matches) > 1) {
                $own = array_values(array_filter($matches, fn ($c) => $c->owning_franchise_id === $twin->owning_franchise_id));
                $matches = count($own) === 1 ? $own : $matches;
            }

            if ($matches === []) {
                $report['address']++;
            } elseif (count($matches) > 1) {
                $report['several'][] = $twin->getKey().' matches '.implode(', ', array_map(fn ($c) => $c->getKey(), $matches));
            } elseif (in_array(self::street($twin->address_line1), self::PLACEHOLDER_STREETS, true)
                || in_array(self::street($matches[0]->address_line1), self::PLACEHOLDER_STREETS, true)) {
                $report['placeholder']++;
            } elseif ($twin->updated_at->gt($twin->created_at)) {
                $report['touched'][] = $twin->getKey();
            } elseif (isset($named[$twin->getKey()])) {
                $report['named'][] = $twin->getKey();
            } else {
                $pairs[] = [$twin, $matches[0]];
            }
        }

        $this->info(sprintf(
            '%s%d twins: %d to merge (%d in the same franchise, %d in another), %d at a different address left alone, %d at a placeholder address left alone, %d match several customers, %d changed by a person, %d named by something else.',
            $dryRun ? '[dry run] ' : '', count($pairs) + $report['address'] + $report['placeholder'] + count($report['several']) + count($report['touched']) + count($report['named']),
            count($pairs), count(array_filter($pairs, fn ($p) => $p[0]->owning_franchise_id === $p[1]->owning_franchise_id)),
            count(array_filter($pairs, fn ($p) => $p[0]->owning_franchise_id !== $p[1]->owning_franchise_id)), $report['address'], $report['placeholder'],
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
            'merged' => $listed,
            'manifest' => $path,
        ]);

        $this->info('Merged '.count($listed).". Manifest: {$path} on the local disk.");
    }

    /** "800 Southeast 4th Avenue" and "800 SE 4th Ave." alike: lower case, no punctuation, the usual short forms. */
    public static function street(?string $line): string
    {
        $s = mb_strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', (string) $line));
        $s = preg_replace(['/\bsouth\s*east\b/', '/\bavenue\b/', '/\bav\b/', '/\bfourth\b/'], ['se', 'ave', 'ave', '4th'], $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Which of these ids some row names: every `…_id` column in the schema,
     * deleted rows included (RemoveWorkizDuplicates::named), less the columns
     * the caller is about to re-point or remove itself.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $except  'table.column'
     * @return array<string, true>
     */
    public static function namedElsewhere(array $ids, array $except): array
    {
        if ($ids === []) {
            return [];
        }

        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('COLUMN_NAME', 'like', '%\_id')
            ->whereIn('DATA_TYPE', ['char', 'varchar'])
            ->get(['TABLE_NAME', 'COLUMN_NAME'])
            ->reject(fn ($c) => in_array($c->TABLE_NAME.'.'.$c->COLUMN_NAME, $except, true));

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
