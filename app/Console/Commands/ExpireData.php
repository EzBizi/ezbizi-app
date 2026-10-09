<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\PhoneCall;
use App\Support\Privacy\DeclaringModels;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Enforce the retention every table states (plan §10).
 *
 * ── The thing this exists to stop being true ─────────────────────
 *
 * Before it, exactly one table in the system had enforced retention: audit
 * events, swept by `ezbizi:expire-audit`. Every other table's policy was a
 * sentence in a docblock, and two of those sentences said out loud that there
 * was no policy at all — "there is no automatic expiry today" on notifications,
 * and nothing whatsoever on the automation outbox, which takes a row for every
 * business change in every organization and had no way to say anything because
 * it holds no personal data and the only place to speak was the personal-data
 * interface.
 *
 * A number in a comment is not retention. This is the thing that makes the
 * numbers true, and it reads them from the same declaration the data inventory
 * publishes — so what a customer is told and what actually happens cannot
 * disagree, because they are one sentence composed from one object.
 *
 * ── Discovered, not configured ───────────────────────────────────
 *
 * There is no list of tables here. Models that implement StatesRetention are
 * found by looking, and a policy of `window()` is the only kind this acts on;
 * `withParent`, `elsewhere` and `forever` are all reported and skipped, with
 * the reason, so a dry run is a readable statement of the whole policy rather
 * than a list of what happened to be deleted tonight.
 *
 * The alternative — a config array of table => days — puts the number somewhere
 * the model cannot see it, which means the inventory has to be told separately
 * and the two go out of step the first time either is edited.
 *
 * ── Audit events are deliberately not swept here ─────────────────
 *
 * `audit_events` declares `RetentionPolicy::elsewhere('ezbizi:expire-audit')`
 * because its retention is four windows keyed on who acted, and a generic
 * one-column sweep cannot express that. Two commands deleting from one table on
 * different rules is the shape of a bug, so this one refuses to touch it and the
 * declaration says where to look instead.
 */
class ExpireData extends Command
{
    protected $signature = 'ezbizi:expire-data {--dry-run : show what would be removed}';

    protected $description = 'Apply the retention policy every table states (plan §10)';

    public function __construct(private readonly DeclaringModels $models)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total  = 0;

        foreach ($this->models->statingRetention() as $class) {
            $policy = $class::retention();
            $table  = (new $class)->getTable();

            if (! $policy->isSwept()) {
                // Printed rather than skipped silently. "Nothing was deleted from
                // contract_signatures" and "contract_signatures is never swept,
                // because a signature does not expire" are different statements,
                // and only the second is a policy.
                $this->line(sprintf('  %-22s %s', $table, $this->why($policy)));

                continue;
            }

            $total += $this->sweep($class, $table, $policy, $dryRun);
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run: {$total} rows would be removed."
            : "Removed {$total} expired rows.");

        return self::SUCCESS;
    }

    /**
     * One table, one window.
     *
     * Deleted through the query builder in chunks, for the same two reasons
     * ExpireAuditEvents gives: a large backlog must not become one enormous
     * transaction, and expiry is a sanctioned exception to the model guards
     * rather than a general delete path anybody can reach.
     */
    private function sweep(string $class, string $table, RetentionPolicy $policy, bool $dryRun): int
    {
        $cutoff = now()->subMonths($policy->months);
        $query  = $this->expirable($class)->where($policy->column, '<', $cutoff);
        $count  = (clone $query)->count();

        $this->line(sprintf(
            '  %-22s %2d months  cutoff %s  %s%d',
            $table, $policy->months, $cutoff->toDateString(),
            $dryRun ? 'would remove ' : 'removing ', $count,
        ));

        if ($count === 0 || $dryRun) {
            return $count;
        }

        $deleted = 0;
        $stuck = [];

        do {
            $ids = (clone $query)->whereNotIn($table.'.id', $stuck)->limit(1000)->pluck($table.'.id');
            $seen = $ids->count();

            // A call's audio lives on Twilio, not in this row: delete it there
            // first, and keep any row whose recording Twilio would not drop, so
            // tomorrow's sweep asks again instead of losing the SID.
            if ($class === PhoneCall::class) {
                $failed = [];
                foreach (PhoneCall::query()->withoutGlobalScopes()->whereIn('id', $ids)->whereNotNull('recording_sid')->get() as $call) {
                    try {
                        $call->forgetRecording();
                    } catch (\Throwable $e) {
                        $failed[] = $call->id;
                        $this->warn("  kept call {$call->id}: Twilio did not delete {$call->recording_sid} ({$e->getMessage()})");
                    }
                }
                $stuck = array_merge($stuck, $failed);
                $ids = $ids->diff($failed);
            }

            $deleted += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
        } while ($seen > 0);

        return $deleted;
    }

    /**
     * The rows a sweep may consider, before the cutoff is applied.
     *
     * Two things have to be true of every query built here, and neither is
     * something a retention window can express on its own:
     *
     *   - it must cross every tenant. This runs on a schedule with no
     *     organization in context, so global scopes are dropped deliberately —
     *     with the tenant scope in place the sweep would silently delete nothing
     *     at all, which is the worst possible failure for a cleanup job because
     *     it looks exactly like success.
     *
     *   - work still owed must survive. The automation outbox is the case: an
     *     event older than the window that has not been delivered is not history,
     *     it is a job the dispatcher still has to run, and deleting it would drop
     *     a business change on the floor. So the outbox narrows to rows that are
     *     finished with — delivered, or dead-lettered.
     */
    private function expirable(string $class): Builder
    {
        $query = $class::query()->withoutGlobalScopes();

        if ($class === AutomationEvent::class) {
            $query->where(fn (Builder $q) => $q
                ->whereNotNull('delivered_at')
                ->orWhereNotNull('failed_at'));
        }

        return $query;
    }

    /** What a non-swept table's policy says, in one line for the report. */
    private function why(RetentionPolicy $policy): string
    {
        return match ($policy->kind) {
            RetentionPolicy::WITH_PARENT => 'removed with its parent record (cascade)',
            RetentionPolicy::ELSEWHERE   => 'enforced by '.$policy->enforcedBy,
            RetentionPolicy::FOREVER     => 'kept indefinitely, deliberately',
            default                      => 'not swept',
        };
    }
}
