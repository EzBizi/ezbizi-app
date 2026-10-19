<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * A-1's Workiz jobs from before the mirror began, read from the v1 API
 * (docs/A1-TOOLS-CLIENTS-JOBS-MIGRATION.md, "Old jobs").
 *
 * `/job/all/` pages from the farthest future back to `start_date`, so reaching
 * 2023 means reading every later job first: hundreds of pages across nine
 * accounts, on the same quota the minute sync spends. Hence:
 *
 *  - PACED: a pause between pages, and a page budget per run.
 *  - STOPS ON THE FIRST REFUSAL. A 429 is the account's quota, shared with the
 *    sync; pushing on would starve the sync too.
 *  - RESUMABLE: the next page per branch is saved after every page. A resumed
 *    branch starts one page back, because a job added or removed between runs
 *    shifts every page by a row, and a re-read row costs nothing.
 *  - LANDS ONLY WHAT THE MIRROR LACKS. A job the sync already mirrors is its
 *    business, and leaving it alone keeps this from racing the sync over it.
 *  - COUNTS PER BRANCH: jobs read, jobs new to the mirror, how many of those
 *    attach to a customer EzBizi already has, and how many distinct clients
 *    will become new customers. `--dry-run` reads and counts the same way and
 *    lands nothing, under progress of its own, so it never moves the real run.
 *    It spends the same API calls as the real run.
 *
 * It writes the mirror and nothing else. The projection turns the new rows into
 * jobs, and creating a job raises no automation event, so an old job that lands
 * as Done sends nobody a text, an email or a review request. Run it after the A1
 * clients import, so the jobs attach to the customers that import made.
 */
class BackfillWorkizJobs extends Command
{
    protected $signature = 'ezbizi:backfill-workiz
        {--since=2023-01-01 : The oldest job date to reach (Y-m-d)}
        {--pages=100 : Most pages to read in this run, across every branch}
        {--pause=10 : Seconds between pages, leaving the minute sync its quota}
        {--restart : Forget the saved progress and start every branch from its first page}
        {--refresh : Land every job read, not only the ones the mirror lacks}
        {--dry-run : Read and count, landing nothing; its own progress, so the real run is not moved}
        {--connection= : Only this Workiz connection, with progress of its own}
        {--pending : Only the connections waiting for their history (queue()), each with progress of its own}';

    protected $description = 'Mirror Workiz jobs from before the mirror began, paced and resumable';

    /** The saved progress: the since date, and per franchise the next page, whether it is done, and the counts. */
    public const PROGRESS = 'ezbizi:backfill-workiz';

    /** Connections waiting for their whole history: every newly connected Workiz account (Jose, 2026-10-19). */
    public const PENDING = 'ezbizi:backfill-workiz:pending';

    /** Import this connection's whole history, paced, on the scheduled `--pending` run. */
    public static function queue(SocialConnection $connection): void
    {
        Cache::forever(self::PENDING, array_values(array_unique([...Cache::get(self::PENDING, []), $connection->getKey()])));
    }

    /** How far this connection's history import has got, or null when none was asked for. */
    public static function state(SocialConnection $connection): ?array
    {
        $at = Cache::get(self::PROGRESS.':'.$connection->getKey())['branches'][$connection->scope_id] ?? null;

        return $at === null && ! in_array($connection->getKey(), Cache::get(self::PENDING, []), true)
            ? null : ['done' => (bool) ($at['done'] ?? false), 'jobs' => (int) ($at['jobs'] ?? 0)];
    }

    private const FRESH = ['page' => 0, 'done' => false, 'jobs' => 0, 'new' => 0, 'attach' => 0, 'clients' => []];

    /**
     * Seconds to wait for one page. A big account's FIRST page from an old date
     * is slow while Workiz builds the list: New Jersey's page 0 since 2023 ran
     * past Laravel's default 30 s twice on 2026-09-29, and answered in 2.3 s
     * once warm (the desk's probe).
     */
    public const PAGE_TIMEOUT = 120;

    public function handle(WorkizJobs $workiz, TenantContext $tenant): int
    {
        $since = (string) $this->option('since');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since is not a Y-m-d date.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $own = $this->option('connection') !== null || $this->option('pending');
        $keyFor = fn (?SocialConnection $c) => self::PROGRESS.($own && $c !== null ? ':'.$c->getKey() : '').($dry ? ':dry-run' : '');
        $load = function (string $key) use ($since) {
            $progress = $this->option('restart') ? [] : Cache::get($key, []);

            // A different window is a different walk: its page numbers mean other jobs.
            if (($progress['since'] ?? $since) !== $since) {
                $progress = [];
            }

            return ['since' => $since] + $progress;
        };
        $key = $keyFor(null);
        $progress = $load($key);

        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('pending'), fn ($q) => $q->whereIn('id', Cache::get(self::PENDING, [])))
            ->orderBy('display_name')
            ->get();

        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()->keyBy(fn (Organization $o) => $o->getKey());

        $budget = max(1, (int) $this->option('pages'));
        $read = 0;

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($own) {
                $key = $keyFor($connection);
                $progress = $load($key);
            }

            $at = $progress['branches'][$connection->scope_id] ?? self::FRESH;

            if ($organization === null || $read >= $budget) {
                continue;
            }

            // Nothing a dry run read was landed, so it has no shifted row to catch.
            if (! $dry) {
                $at['page'] = max(0, $at['page'] - 1);
            }

            while ($read < $budget && ! $at['done']) {
                if ($read > 0) {
                    Sleep::for(max(0, (int) $this->option('pause')))->seconds();
                }

                $page = $workiz->page($connection, $since, $at['page'], self::PAGE_TIMEOUT);
                $read++;

                if ($page['failed'] !== null) {
                    // $failed is a status and a sentence, never the URL: the token is in it.
                    $this->error("{$connection->display_name}: {$page['failed']} Stopped at page {$at['page']}; progress is saved, run again later.");
                    Log::warning("Workiz backfill: {$connection->display_name}: {$page['failed']}");

                    return self::FAILURE;
                }

                $tally = $tenant->run($organization, fn () => $this->tally($connection, $page['jobs'], $at['clients'], $dry));
                $at = ['jobs' => $at['jobs'] + count($page['jobs']), 'new' => $at['new'] + $tally['new'],
                    'attach' => $at['attach'] + $tally['attach'], 'clients' => $tally['clients'],
                    'done' => count($page['jobs']) + ($page['skipped'] ?? 0) < WorkizJobs::PAGE, 'page' => $at['page'] + 1];

                $progress['branches'][$connection->scope_id] = $at;
                Cache::forever($key, $progress);
            }

            if ($own && $at['done'] && ! $dry) {
                Cache::forever(self::PENDING, array_values(array_diff(Cache::get(self::PENDING, []), [$connection->getKey()])));
            }
        }

        $done = 0;

        foreach ($connections as $connection) {
            $at = ($own ? $load($keyFor($connection)) : $progress)['branches'][$connection->scope_id] ?? self::FRESH;
            $done += (int) $at['done'];
            $this->line(($dry ? '[dry run] ' : '')."{$connection->display_name}: {$at['jobs']} jobs read, {$at['new']} new: "
                ."{$at['attach']} attach to a customer, ".count($at['clients']).' new customers. '
                ."Next page {$at['page']}".($at['done'] ? ', done.' : '.'));
        }

        $this->info($done === $connections->count()
            ? 'Every branch is done.'
            : "Not finished: {$read} pages read this run. Run it again to go on.");

        if ($dry) {
            $this->info('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Count one page, and land what the mirror lacks unless this is a dry run.
     *
     * A new job whose client already has a customer ATTACHES to them: the
     * projection finds the client's key, as the A1 import registered it. A client
     * with no customer yet will become a new one; `$clients` holds each such
     * client once, across pages and runs.
     *
     * @return array{new: int, attach: int, clients: array<int, true>}
     */
    private function tally(SocialConnection $connection, array $jobs, array $clients, bool $dry): array
    {
        $mirrored = WorkizJob::query()
            ->where('franchise_id', $connection->scope_id)
            ->whereIn('remote_id', array_column($jobs, 'UUID'))
            ->pluck('remote_id')
            ->flip();

        $fresh = array_filter($jobs, fn (array $job) => ! $mirrored->has((string) ($job['UUID'] ?? '')));

        $customers = WorkizClient::query()
            ->where('franchise_id', $connection->scope_id)
            ->whereIn('client_id', array_map(fn (array $job) => (int) ($job['ClientId'] ?? 0), $fresh))
            ->whereNotNull('customer_id')
            ->pluck('client_id')
            ->flip();

        $attach = 0;

        foreach ($fresh as $payload) {
            $client = (int) ($payload['ClientId'] ?? 0);

            if ($customers->has($client)) {
                $attach++;
            } elseif ($client > 0) {
                $clients[$client] = true;
            }

            if (! $dry) {
                WorkizJob::land($connection, $payload);
            }
        }

        // A branch moved to v2 has rows landed through v1, which v2 can only
        // re-read by a JOB- id they do not carry (2026-10-05). Landing every
        // job once through v2 gives each its id, and its status as Workiz
        // holds it today.
        if ($this->option('refresh') && ! $dry) {
            foreach (array_filter($jobs, fn (array $job) => $mirrored->has((string) ($job['UUID'] ?? ''))) as $payload) {
                WorkizJob::land($connection, $payload);
            }
        }

        return ['new' => count($fresh), 'attach' => $attach, 'clients' => $clients];
    }
}
