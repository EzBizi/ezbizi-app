<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apply the retention policy (plan §10).
 *
 * The only thing permitted to remove audit rows. AuditEvent blocks deletes
 * through Eloquent, so this deletes at query-builder level — narrow, explicit,
 * and auditable, rather than opening a delete path on the model that any code
 * could reach.
 *
 * Retention differs by category because the categories answer different
 * questions:
 *
 *   business    3 months  — "what happened in my account?"
 *   developer   3 months  — debugging a report that arrives weeks later
 *   crash       1 month   — fix it or it isn't getting fixed
 *   staff       12 months — evidence of EzBizi access to client data (§4.2)
 *
 * Business audit was one month and is now three (owner decision, 2026-08-07).
 * One month meant a client asking "who changed this in the spring?" in June was
 * told the record no longer existed — a quarter is the shortest window in which
 * that question is usually still answerable. Crash logs stay at one month
 * because they are only useful while the build that produced them is current.
 *
 * Staff access is the outlier on purpose: it's the record you need when a client
 * asks who touched their data, and that question can arrive long after the fact.
 */
class ExpireAuditEvents extends Command
{
    protected $signature = 'ezbizi:expire-audit {--dry-run : show what would be removed}';

    /** action prefix => months retained */
    private const RETENTION = [
        'staff'     => 12,
        'developer' => 3,
        'crash'     => 1,
        'error'     => 1,
    ];

    private const DEFAULT_MONTHS = 3;

    /**
     * `artisan list` is where somebody checks what the policy actually is, so
     * the description says it rather than pointing at the plan alone. Built from
     * the constants rather than typed out again — a second copy of the number is
     * a second thing to forget when the policy next changes.
     */
    public function __construct()
    {
        // Before parent::__construct(), not after: the base constructor is what
        // hands this property to Symfony, and Symfony keeps its own private copy.
        // Assigning afterwards changes the property and leaves `artisan list`
        // showing the old text.
        $this->description = sprintf(
            'Apply audit retention policy (plan §10) — %d months of business activity, %d of staff access',
            self::DEFAULT_MONTHS,
            self::RETENTION['staff'],
        );

        parent::__construct();
    }

    public function handle(AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total  = 0;
        $removed = [];

        // Staff events are identified by actor_type, not action prefix — a staff
        // member creating a company writes 'company.created', which must still
        // fall under the 12-month rule.
        $removed['staff'] = $this->expire(
            'staff activity',
            AuditEvent::query()->where('actor_type', AuditEvent::ACTOR_STAFF),
            self::RETENTION['staff'],
            $dryRun
        );

        $total += $removed['staff'];

        foreach (['developer', 'crash', 'error'] as $prefix) {
            $removed[$prefix] = $this->expire(
                "{$prefix} events",
                AuditEvent::query()
                    ->where('actor_type', '!=', AuditEvent::ACTOR_STAFF)
                    ->where('action', 'like', "{$prefix}.%"),
                self::RETENTION[$prefix],
                $dryRun
            );

            $total += $removed[$prefix];
        }

        // Everything else: ordinary business activity.
        $prefixes = array_map(fn ($p) => "{$p}.%", ['developer', 'crash', 'error']);
        $business = AuditEvent::query()
            ->where('actor_type', '!=', AuditEvent::ACTOR_STAFF)
            ->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $p) {
                    $q->where('action', 'not like', $p);
                }
            });

        $removed['business'] = $this->expire('business activity', $business, self::DEFAULT_MONTHS, $dryRun);
        $total += $removed['business'];

        /*
         * The expiry writes its own entry (§10).
         *
         * AuditEvent's docblock has always said "Expiry happens via the
         * retention command, which is itself audited" — and it was not. This is
         * the only code in the application permitted to remove audit rows, it
         * does so through the query builder specifically to get around the
         * model's append-only guard, and it left nothing behind saying it had
         * run. A deletion path with no record of the deletion is the one hole
         * that makes every other guarantee on that table unprovable: "the row
         * isn't there" and "the row was never written" become the same answer.
         *
         * Written AFTER the deletes, so it cannot be caught by its own run, and
         * only when something was actually removed — a nightly job that removed
         * nothing destroyed no history, and a row a day saying so is noise in
         * the table it is supposed to keep readable.
         *
         * No tenant and no actor: this is the scheduler, and `system` is what
         * that is called here.
         */
        if (! $dryRun && $total > 0) {
            $audit->log('audit.expired', metadata: [
                'removed' => array_filter($removed),
                'total'   => $total,
                'policy'  => self::RETENTION + ['business' => self::DEFAULT_MONTHS],
            ]);
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run: {$total} events would be removed."
            : "Removed {$total} expired audit events.");

        return self::SUCCESS;
    }

    private function expire(string $label, $query, int $months, bool $dryRun): int
    {
        $cutoff = now()->subMonths($months);
        $count  = (clone $query)->where('occurred_at', '<', $cutoff)->count();

        $this->line(sprintf(
            '  %-18s %2d months  cutoff %s  %s%d',
            $label, $months, $cutoff->toDateString(), $dryRun ? 'would remove ' : 'removing ', $count
        ));

        if ($count === 0 || $dryRun) {
            return $count;
        }

        // Query-builder delete, bypassing the model's append-only guard. The
        // guard exists to stop application code deleting history; expiry is the
        // one sanctioned exception, and it lives here alone.
        //
        // Chunked so a large backlog doesn't hold one enormous transaction.
        $deleted = 0;
        do {
            $batch = DB::table('audit_events')
                ->whereIn('id', (clone $query)->where('occurred_at', '<', $cutoff)->limit(1000)->pluck('id'))
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }
}
