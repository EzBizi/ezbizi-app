<?php

namespace App\Console\Commands;

use App\Jobs\DeliverAutomationEvent;
use App\Models\AutomationEvent;
use Illuminate\Console\Command;

/**
 * Move due outbox events onto the queue (plan §5.5).
 *
 * The reader half of the transactional outbox. Nothing dispatches a job from
 * inside the business transaction — that is the bug the outbox exists to avoid,
 * because a worker can pick the job up before the commit lands, or the commit
 * can fail after the job was queued.
 *
 * So the write path only ever inserts a row, and this sweeps.
 */
class DispatchAutomationEvents extends Command
{
    protected $signature = 'ezbizi:dispatch-automation-events {--limit=500}';

    protected $description = 'Queue delivery for automation events that are due';

    public function handle(): int
    {
        /*
         * Events are LEASED, not just selected.
         *
         * Without this the sweep re-dispatched every event that was still in
         * flight, once a minute, for as long as it took — which also defeated
         * the job's widening backoff completely: a delivery waiting 15 minutes
         * for its next attempt got a brand new job queued 14 times in the
         * meantime, and each one ran immediately.
         *
         * The lease is longer than the largest backoff (900s), or the sweep
         * simply out-races the retry again. It expires rather than being cleared,
         * so an event whose worker died is picked up later instead of stranded.
         */
        $lease = now()->subSeconds(1200);

        $due = AutomationEvent::withoutGlobalScopes()
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('available_at', '<=', now())
            ->where('attempts', '<', AutomationEvent::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', $lease))
            ->orderBy('occurred_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $event) {
            // Claimed before dispatch, so two overlapping sweeps cannot both
            // queue the same event.
            $claimed = AutomationEvent::withoutGlobalScopes()
                ->whereKey($event->getKey())
                ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', $lease))
                ->update(['queued_at' => now()]);

            if ($claimed === 1) {
                DeliverAutomationEvent::dispatch($event->getKey());
            }
        }

        $this->info($due->isEmpty()
            ? 'No automation events were due.'
            : "Queued {$due->count()} automation event(s).");

        return self::SUCCESS;
    }
}
