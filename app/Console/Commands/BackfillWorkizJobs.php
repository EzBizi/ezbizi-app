<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
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
        {--restart : Forget the saved progress and start every branch from its first page}';

    protected $description = 'Mirror Workiz jobs from before the mirror began, paced and resumable';

    /** The saved progress: the since date, and per franchise the next page and whether it is done. */
    public const PROGRESS = 'ezbizi:backfill-workiz';

    public function handle(WorkizJobs $workiz, TenantContext $tenant): int
    {
        $since = (string) $this->option('since');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since is not a Y-m-d date.');

            return self::FAILURE;
        }

        $progress = $this->option('restart') ? [] : Cache::get(self::PROGRESS, []);

        // A different window is a different walk: its page numbers mean other jobs.
        if (($progress['since'] ?? $since) !== $since) {
            $progress = [];
        }

        $progress['since'] = $since;

        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->orderBy('display_name')
            ->get();

        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()->keyBy(fn (Organization $o) => $o->getKey());

        $budget = max(1, (int) $this->option('pages'));
        $read = 0;

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;
            $at = $progress['branches'][$connection->scope_id] ?? ['page' => 0, 'landed' => 0, 'done' => false];

            if ($organization === null || $read >= $budget) {
                continue;
            }

            $at['page'] = max(0, $at['page'] - 1);

            while ($read < $budget && ! $at['done']) {
                if ($read > 0) {
                    Sleep::for(max(0, (int) $this->option('pause')))->seconds();
                }

                $page = $workiz->page($connection, $since, $at['page']);
                $read++;

                if ($page['failed'] !== null) {
                    // $failed is a status and a sentence, never the URL: the token is in it.
                    $this->error("{$connection->display_name}: {$page['failed']} Stopped at page {$at['page']}; progress is saved, run again later.");
                    Log::warning("Workiz backfill: {$connection->display_name}: {$page['failed']}");

                    return self::FAILURE;
                }

                $at['landed'] += $tenant->run($organization, fn () => $this->landNew($connection, $page['jobs']));
                $at['done'] = count($page['jobs']) < WorkizJobs::PAGE;
                $at['page']++;

                $progress['branches'][$connection->scope_id] = $at;
                Cache::forever(self::PROGRESS, $progress);
            }
        }

        $done = 0;

        foreach ($connections as $connection) {
            $at = $progress['branches'][$connection->scope_id] ?? ['page' => 0, 'landed' => 0, 'done' => false];
            $done += (int) $at['done'];
            $this->line("{$connection->display_name}: {$at['landed']} landed, next page {$at['page']}".($at['done'] ? ', done.' : '.'));
        }

        $this->info($done === $connections->count()
            ? 'Every branch is done.'
            : "Not finished: {$read} pages read this run. Run it again to go on.");

        return self::SUCCESS;
    }

    /** Land the jobs the mirror does not have yet; returns how many. */
    private function landNew(SocialConnection $connection, array $jobs): int
    {
        $known = WorkizJob::query()
            ->where('franchise_id', $connection->scope_id)
            ->whereIn('remote_id', array_column($jobs, 'UUID'))
            ->pluck('remote_id')
            ->flip();

        $landed = 0;

        foreach ($jobs as $payload) {
            if (! $known->has((string) ($payload['UUID'] ?? ''))) {
                $landed += WorkizJob::land($connection, $payload) ? 1 : 0;
            }
        }

        return $landed;
    }
}
