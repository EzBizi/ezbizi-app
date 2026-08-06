<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
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
 *   business    1 month   — "what happened in my account?"
 *   developer   3 months  — debugging a report that arrives weeks later
 *   crash       1 month   — fix it or it isn't getting fixed
 *   staff       12 months — evidence of EzBizi access to client data (§4.2)
 *
 * Staff access is the outlier on purpose: it's the record you need when a client
 * asks who touched their data, and that question can arrive long after the fact.
 */
class ExpireAuditEvents extends Command
{
    protected $signature = 'ezbizi:expire-audit {--dry-run : show what would be removed}';

    protected $description = 'Apply audit retention policy (plan §10)';

    /** action prefix => months retained */
    private const RETENTION = [
        'staff'     => 12,
        'developer' => 3,
        'crash'     => 1,
        'error'     => 1,
    ];

    private const DEFAULT_MONTHS = 1;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total  = 0;

        // Staff events are identified by actor_type, not action prefix — a staff
        // member creating a company writes 'company.created', which must still
        // fall under the 12-month rule.
        $total += $this->expire(
            'staff activity',
            AuditEvent::query()->where('actor_type', AuditEvent::ACTOR_STAFF),
            self::RETENTION['staff'],
            $dryRun
        );

        foreach (['developer', 'crash', 'error'] as $prefix) {
            $total += $this->expire(
                "{$prefix} events",
                AuditEvent::query()
                    ->where('actor_type', '!=', AuditEvent::ACTOR_STAFF)
                    ->where('action', 'like', "{$prefix}.%"),
                self::RETENTION[$prefix],
                $dryRun
            );
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

        $total += $this->expire('business activity', $business, self::DEFAULT_MONTHS, $dryRun);

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
