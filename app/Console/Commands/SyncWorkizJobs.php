<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizJobAttributes;
use Illuminate\Console\Command;

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
        {--connection= : Sync only this connection id}';

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
        $failed = [];

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
                    $attributes = WorkizJobAttributes::fromPayload($payload);

                    // A row with no id cannot be upserted idempotently; keying
                    // on '' would collapse every such job onto one row.
                    if ($attributes['remote_id'] === '') {
                        continue;
                    }

                    WorkizJob::query()->updateOrCreate(
                        [
                            // The FRANCHISE, not the connection. Disconnect
                            // deletes the connection row and reconnect mints a
                            // new id, so a connection-keyed upsert would land
                            // every job a second time. One branch holds one
                            // Workiz account, so the franchise is the stable
                            // name for the book a job came from.
                            'franchise_id' => $connection->scope_id,
                            'remote_id' => $attributes['remote_id'],
                        ],
                        $attributes + [
                            // Recorded, but never part of the identity — see
                            // the migration. Placement comes from the
                            // CONNECTION and never from the payload: Workiz
                            // cannot know which EzBizi franchise a job belongs
                            // to, and a payload that claimed to would be a
                            // caller-supplied tenant id.
                            'connection_id' => $connection->getKey(),
                            'synced_at' => now(),
                        ],
                    );

                    $count++;
                }

                return $count;
            });
        }

        $this->info($landed.' Workiz jobs mirrored from '.$connections->count().' connected branches, since '.$since.'.');

        foreach ($failed as $branch => $why) {
            $this->warn($branch.': '.$why);
        }

        return self::SUCCESS;
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
