<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizTwins;
use Carbon\CarbonInterface;
use App\Support\Workiz\WorkizV2;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
    public function handle(WorkizJobs $workiz, TenantContext $tenant, AuditLogger $audit): int
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
                    $count += WorkizJob::land($connection, $payload) ? 1 : 0;
                }

                return $count;
            });

            // Only a complete listing can say a job is missing from it.
            $listed = $result['failed'] === null ? $since : null;
            $reread = $tenant->run($organization, fn () => $this->reconcile($workiz, $connection, $runStarted, $listed, $audit));

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
    private function reconcile(WorkizJobs $workiz, SocialConnection $connection, CarbonInterface $runStarted, ?string $listedSince, AuditLogger $audit): array
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
            ->get();

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
            ->get();

        $refreshed = 0;
        $failed = null;
        $gone = [];

        foreach ($stale->concat($oldest) as $row) {
            $result = $workiz->find($connection, (string) $row->remote_id);

            if ($result['failed'] !== null) {
                $failed = $result['failed'];

                // Moved back as well: a job Workiz cannot answer for (v2 answers
                // 500 for one New Jersey job, measured 2026-10-05) would
                // otherwise stop this branch's re-reads on the same row for ever.
                WorkizJob::query()->whereKey($row->id)->update(['synced_at' => now()]);

                break;
            }

            if ($result['job'] === null) {
                // Workiz holds no such job (it answers 204). Deleted there when
                // it was still open and the complete listing this run just read
                // did not hold it either: retired. Otherwise moved to the back
                // of the queue rather than asked about again next minute.
                // Held while services.workiz.retire_deleted is off: a deleted job
                // can still be holding a seat its replacement has not taken yet.
                if (config('services.workiz.retire_deleted') && $listedSince !== null && ! in_array($row->status, self::CLOSED, true)
                    && $row->scheduled_for !== null && $row->scheduled_for->gte($listedSince)) {
                    $gone[] = $row;
                } elseif (config('services.workiz.retire_deleted') && WorkizV2::uses($connection) && self::searchFindsNone($connection, $row)) {
                    // A past or finished job the listing never covers: v2's search,
                    // which reaches every job, is the second signal. 36 of
                    // Nationwide's September jobs were deleted in Workiz and kept
                    // here, so EzBizi's counts ran past Workiz's report (2026-10-16).
                    $gone[] = $row;
                } else {
                    WorkizJob::query()->whereKey($row->id)->update(['synced_at' => now()]);
                }

                continue;
            }

            $refreshed += WorkizJob::land($connection, $result['job']) ? 1 : 0;
        }

        $this->retire($gone, $audit);

        return ['refreshed' => $refreshed, 'failed' => $failed];
    }

    /**
     * A job Workiz deleted: missing from the branch's complete listing, and
     * asked for by its id, 204.
     *
     * A-1 moved Massachusetts into its own Workiz account on 2026-10-05 by
     * deleting 56 upcoming jobs from Nationwide's, and the mirror kept every
     * one: the board, the openings and the jobs list showed visits nobody was
     * going to make, beside the copies in the new account. The mirror row goes,
     * because those readers read this table directly. Its EzBizi job is
     * soft-deleted only when it is still exactly what the projection wrote and
     * nothing in EzBizi names it (named()). Otherwise it stays, and the audit
     * row says why.
     *
     * @param  list<WorkizJob>  $rows
     */
    private function retire(array $rows, AuditLogger $audit): void
    {
        $named = self::named(array_values(array_filter(array_map(fn (WorkizJob $row) => $row->job_id, $rows))));

        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $audit, $named) {
                $job = $row->job_id === null ? null : Job::query()->find($row->job_id);
                $untouched = $job !== null && collect(ProjectWorkizRecords::followedValues($job))
                    ->every(fn ($value, $field) => (string) ($value ?? '') === (string) ($row->projected[$field] ?? ''));

                $verdict = match (true) {
                    $job === null => 'none',
                    ! $untouched => 'kept: changed in EzBizi',
                    isset($named[$job->getKey()]) => 'kept: something in EzBizi names it',
                    default => 'deleted',
                };

                $released = $verdict === 'deleted' ? self::release($job) : [];

                $audit->log('workiz.job_gone', $job, metadata: [
                    'remote_id' => $row->remote_id,
                    'serial_id' => $row->serial_id,
                    'status' => $row->status,
                    'scheduled_for' => $row->scheduled_for?->format('Y-m-d H:i:s'),
                    'ezbizi_job' => $verdict,
                    'leads_released' => $released,
                ]);

                $row->delete();
            });
        }
    }

    /**
     * Which of these jobs something in EzBizi names, so that a Workiz deletion
     * keeps them: a priced line, an estimate, a payment, a lead somebody booked
     * as the job, an edit somebody made (the API audits every one) -- Customers,
     * 2026-10-05: money must never be left hanging off a deleted job.
     *
     * Not, the same day: a lead the matcher linked by phone (`job_matched_by`),
     * which is found rather than made; an automation event, the outbox of what
     * Workiz's own status changes fired; or this command's audit row. On those
     * alone 45 of the 61 jobs A-1 moved from Nationwide to Massachusetts stayed
     * in EzBizi beside their Massachusetts copies.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public static function named(array $ids): array
    {
        $named = WorkizTwins::namedElsewhere($ids, ['workiz_jobs.job_id', 'leads.job_id', 'automation_events.subject_id', 'audit_events.target_id']);

        $made = DB::table('leads')->whereIn('job_id', $ids)->where(fn ($q) => $q->whereNull('job_matched_by')->orWhere('job_matched_by', '<>', 'phone'))->pluck('job_id');
        $edited = DB::table('audit_events')->whereIn('target_id', $ids)->where('action', '<>', 'workiz.job_gone')->pluck('target_id');

        return $named + array_fill_keys($made->merge($edited)->all(), true);
    }

    /**
     * Soft-delete a job Workiz deleted, and let go of the leads the matcher
     * linked to it, so it finds the job each became now (MatchLeadsToJobs only
     * looks at a lead with no job). Returns their ids.
     *
     * @return list<string>
     */
    public static function release(Job $job): array
    {
        $leads = Lead::query()->where('job_id', $job->getKey())->where('job_matched_by', 'phone')->pluck('id')->all();
        Lead::query()->whereKey($leads)->update(['job_id' => null, 'job_matched_by' => null]);
        $job->delete();

        return $leads;
    }

    /**
     * Workiz's own words for a job that has finished, one way or the other.
     * Everything else — including "done pending approval", which still becomes
     * Done — is open and worth re-reading.
     */
    private const CLOSED = ['Done', 'Canceled'];

    /** v2's search answers for this job's number with no job of that number. A refusal is not an answer. */
    private static function searchFindsNone(SocialConnection $connection, WorkizJob $row): bool
    {
        if ($row->serial_id === null) {
            return false;
        }

        $read = WorkizV2::send($connection, 'GET', '/search', ['term' => (string) $row->serial_id, 'type' => 'job', 'limit' => 15]);

        return $read['refused'] === null && is_array($read['data']['data'] ?? null)
            && ! collect($read['data']['data'])->contains(fn ($hit) => (string) ($hit['serialId'] ?? '') === (string) $row->serial_id);
    }

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
