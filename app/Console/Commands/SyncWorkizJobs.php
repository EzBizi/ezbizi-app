<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizJobAttributes;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirror every connected branch's Workiz jobs into `workiz_jobs`.
 *
 * ── A ROLLING WINDOW, NOT A WATERMARK, AND THAT IS THE WHOLE DESIGN ──
 *
 * The obvious shape is a watermark: remember the newest job seen, ask for
 * everything after it. It is wrong here, and quietly.
 *
 * Workiz's `start_date` filters on when a job was CREATED. A job booked three
 * months ago whose status changed this morning is not "after" any recent
 * watermark, so a watermark sweep would never see the change — and a status
 * change is the single thing this mirror exists to carry. A1 Tools uses a
 * rolling window for exactly this reason and has done for months.
 *
 * So: each run re-reads the last `--window` days and upserts. Re-reading is
 * not waste, it is how an edit to an older job arrives.
 *
 * ── THE WINDOW IS A QUOTA DECISION, AND THE QUOTA IS REAL ───────
 *
 * Workiz refuses with `429 {"error":true,"code":429,"msg":"Account reached Api
 * Quotas - Please try again later..."}`. Measured on A-1's own live sync on
 * 2026-09-23: 26 refusals in one day at a TWO-minute cadence with a 30-day
 * window. The quota belongs to the Workiz ACCOUNT, so EzBizi and A1 Tools are
 * spending the same allowance on the same nine accounts until A1's is switched
 * off.
 *
 * That is why the default window is two days rather than thirty: at one page
 * of 100 it is usually a single request per branch per run, where A1's 30-day
 * window is several. Widen it with `--window` for a backfill, not for a tick.
 *
 * ── ONE BRANCH'S FAILURE IS RECORDED, NOT PROPAGATED ────────────
 *
 * The nine accounts are disjoint and the same people book across them, so a
 * branch silently missing from a run makes every number built on this table
 * WRONG rather than incomplete. A refused branch is named; the rest still run.
 *
 * ── READ-ONLY, AND THIS COMMAND STAYS THAT WAY ──────────────────
 *
 * Nothing here calls a Workiz POST endpoint. Two-way sync is a separate path
 * with its own credential (`auth_secret`, which this connection does not
 * store) and its own decision.
 *
 * ── IT RAISES NO AUTOMATION EVENT, DELIBERATELY ─────────────────
 *
 * Landing a row here emits nothing. Automations ruled that a Workiz job's
 * status change belongs on the projected `jobs` row rather than beside it, so
 * there is one `job.status_changed` in the product rather than two that mean
 * the same sentence. Whoever writes that projection inherits the backfill
 * problem in the same change: A-1 has 7,770 stored jobs and one existing rule
 * would turn a first import into 7,770 notifications.
 */
class SyncWorkizJobs extends Command
{
    protected $signature = 'ezbizi:sync-workiz
        {--since= : Read from this date (Y-m-d) instead of the rolling window}
        {--window=2 : How many days back to re-read on every run}
        {--connection= : Sync only this connection id}
        {--reconcile=2 : How many past jobs per branch to re-read from Workiz each run}
        {--verify=1 : How many jobs per branch, of ANY status, to re-read least-recently-read first}';

    protected $description = 'Mirror Workiz jobs for every connected branch';

    /**
     * DEPENDENCIES ARRIVE HERE, NOT IN A CONSTRUCTOR, AND THAT IS NOT STYLE.
     *
     * Laravel resolves every discovered command at BOOT to read its signature.
     * Constructor-injecting `WorkizJobs` — which takes `SocialConnectionAccess`
     * — therefore built a chunk of the container before the test environment's
     * database configuration was in place, and every DB-touching test in this
     * worktree failed with
     *
     *     SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'
     *
     * which names MySQL, names a user, and has nothing to do with either. The
     * same three tests passed with this file moved out of the directory and
     * nothing else changed. `handle()` is resolved when the command RUNS, so
     * the same injection here costs nothing at boot.
     */
    public function handle(WorkizJobs $workiz, TenantContext $tenant): int
    {
        /*
         * Unscoped to FIND the work, then into each tenant to DO it.
         *
         * The scheduler is nobody: no request and no user, so the global scope
         * would filter every connection to nothing and this would report a
         * clean run having read from no one. And deliberately no `inView()`
         * filter — that answers "what may this person see", which is a question
         * about a request. A sweep legitimately reaches every connected
         * account. `SyncLeads` makes the same two points.
         */
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No Workiz accounts are connected.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()
            ->keyBy(fn (Organization $o) => $o->getKey());

        $since = $this->option('since')
            ?: now()->subDays(max(1, (int) $this->option('window')))->toDateString();

        $landed = 0;
        $refreshed = 0;
        $failed = [];

        // Anything this run's sweep writes is newer than this, so the re-read
        // below can tell "refreshed just now" from "left behind".
        $runStarted = now();

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($organization === null) {
                $failed[$this->nameOf($connection)] = 'No organization for this connection.';

                continue;
            }

            $result = $workiz->raw($connection, since: $since);

            if ($result['failed'] !== null) {
                // BY NAME, so a branch that answered nothing is visible rather
                // than merely absent from the total.
                $failed[$this->nameOf($connection)] = $result['failed'];
            }

            $landed += $tenant->run($organization, function () use ($connection, $result) {
                $count = 0;

                foreach ($result['jobs'] as $payload) {
                    $count += $this->land($connection, $payload) ? 1 : 0;
                }

                return $count;
            });

            $reread = $tenant->run($organization, fn () => $this->reconcile($workiz, $connection, $runStarted));

            $refreshed += $reread['refreshed'];

            if ($reread['failed'] !== null) {
                $failed[$this->nameOf($connection).' (re-reading past jobs)'] = $reread['failed'];
            }
        }

        $this->info($landed.' Workiz jobs mirrored from '.$connections->count().' connected branches, since '.$since.'.');

        if ($refreshed > 0) {
            $this->info($refreshed.' past jobs re-read from Workiz.');
        }

        foreach ($failed as $branch => $why) {
            $this->warn($branch.': '.$why);

            // Logged as well: the scheduler throws console output away, so a
            // refusal otherwise leaves no trace and the quota cannot be measured.
            // $why is a status code and a sentence, never the URL with its token.
            Log::warning('Workiz sync: '.$branch.': '.$why);
        }

        return self::SUCCESS;
    }

    /**
     * Write one Workiz payload into the mirror. The sweep and the re-read both
     * come through here, so there is one key and one set of rules.
     */
    private function land(SocialConnection $connection, array $payload): bool
    {
        $attributes = WorkizJobAttributes::fromPayload($payload);

        // A row with no id cannot be upserted idempotently; keying on '' would
        // collapse every such job onto one row.
        if ($attributes['remote_id'] === '') {
            return false;
        }

        WorkizJob::query()->updateOrCreate(
            [
                // The FRANCHISE, not the connection. Disconnect deletes the
                // connection row and reconnect mints a new id, so a
                // connection-keyed upsert would land every job a second time.
                // One branch holds one Workiz account, so the franchise is the
                // stable name for the book a job came from.
                'franchise_id' => $connection->scope_id,
                'remote_id' => $attributes['remote_id'],
            ],
            $attributes + [
                // Recorded, but never part of the identity. Placement comes
                // from the CONNECTION and never from the payload: Workiz cannot
                // know which EzBizi franchise a job belongs to, and a payload
                // that claimed to would be a caller-supplied tenant id.
                'connection_id' => $connection->getKey(),
                'synced_at' => now(),
            ],
        );

        return true;
    }

    /**
     * Re-read a few past jobs this branch's sweep can no longer see.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────
     *
     * `/job/all/` answers only for upcoming appointments, so a job whose date
     * has passed is never swept again and stays at whatever status it last had.
     * That is half of the bug Jose reported as "all the jobs show as
     * submitted": 1,323 past-dated rows still read as open on 2026-09-24, and
     * Workiz, asked directly, said most of them had closed.
     *
     * ── WHICH ROWS, AND WHY IN THAT ORDER ───────────────────────
     *
     * Only rows this run did not already refresh. Open jobs first (Submitted,
     * Pending, In progress, done pending approval), because a past job in one
     * of those states has almost certainly moved and it is the row somebody
     * sees as wrong. Then Done or Canceled jobs from the last thirty days,
     * where approvals and payments still land. Least recently re-read first
     * within each, so the whole set rotates rather than the same rows being
     * asked about every minute.
     *
     * Done or Canceled more than thirty days ago is settled history and is not
     * re-read at all. That keeps the steady-state cost at the size of what is
     * still live rather than the size of the account's past.
     *
     * ── THE BUDGET, AND WHY IT STOPS ON THE FIRST REFUSAL ───────
     *
     * Workiz refuses with `429 "Account reached Api Quotas"`, and the quota is
     * the ACCOUNT's, shared with A1 Tools until its sync is switched off. So a
     * few rows per branch per run: the backlog clears within hours and the
     * per-minute sweep keeps its allowance. One refusal ends this branch's
     * re-reads for the run rather than spending more of an allowance that has
     * already run out.
     *
     * @return array{refreshed: int, failed: ?string}
     */
    private function reconcile(WorkizJobs $workiz, SocialConnection $connection, CarbonInterface $runStarted): array
    {
        $budget = max(0, (int) $this->option('reconcile'));

        $stale = $budget === 0 ? collect() : WorkizJob::query()
            ->where('franchise_id', $connection->scope_id)
            ->where('synced_at', '<', $runStarted)
            ->where(fn ($q) => $q
                ->whereNotIn('status', self::CLOSED)
                ->orWhere('scheduled_for', '>=', now()->subDays(30)))
            ->orderByRaw("CASE WHEN status IN ('Done', 'Canceled') THEN 1 ELSE 0 END")
            ->orderBy('synced_at')
            ->limit($budget)
            ->get(['id', 'remote_id']);

        /*
         * "SETTLED" HISTORY IS NOT SETTLED. Measured 2026-09-25 against Workiz's
         * own Done Pending Approval tab for Connecticut: of the first 48 jobs
         * there that the mirror holds, 6 were Done or Canceled here. A1 Tools'
         * copy, frozen once its window moved on, and never re-read because the
         * budget above skips closed jobs older than thirty days.
         *
         * So a second, separate slice: the row read least recently, whatever
         * its status. Every job is checked against Workiz eventually, at a
         * constant cost of `--verify` calls per branch per run, and the open
         * jobs above keep their own pace.
         */
        $verify = max(0, (int) $this->option('verify'));

        $oldest = $verify === 0 ? collect() : WorkizJob::query()
            ->where('franchise_id', $connection->scope_id)
            ->where('synced_at', '<', $runStarted)
            ->whereNotIn('id', $stale->modelKeys())
            ->orderBy('synced_at')
            ->limit($verify)
            ->get(['id', 'remote_id']);

        $refreshed = 0;

        foreach ($stale->concat($oldest) as $row) {
            $result = $workiz->find($connection, (string) $row->remote_id);

            if ($result['failed'] !== null) {
                return ['refreshed' => $refreshed, 'failed' => $result['failed']];
            }

            if ($result['job'] === null) {
                // Workiz answered but holds no such job. Moved to the back of
                // the queue rather than asked about again next minute.
                WorkizJob::query()->whereKey($row->id)->update(['synced_at' => now()]);

                continue;
            }

            $refreshed += $this->land($connection, $result['job']) ? 1 : 0;
        }

        return ['refreshed' => $refreshed, 'failed' => null];
    }

    /**
     * Workiz's own words for a job that has finished, one way or the other.
     * Everything else — including "done pending approval", which still becomes
     * Done — is open and worth re-reading.
     */
    private const CLOSED = ['Done', 'Canceled'];

    /**
     * Workiz cannot tell us its own account's name, so a connection with no
     * display name still has to be identifiable in a failure line.
     */
    private function nameOf(SocialConnection $connection): string
    {
        $name = trim((string) $connection->display_name);

        return $name === '' ? 'connection '.$connection->getKey() : $name;
    }
}
