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
            // Exhausted leases must still be recovered to record a terminal outcome.
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', $lease))
            ->orderBy('occurred_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $event) {
            // One record's events in the order they happened: while an earlier
            // one for the same subject is still undelivered, this one waits,
            // so parallel workers never run them out of order (desk, 11-18).
            // A blocker must already be due (a rule delayed an hour must not
            // hold a reply), and one stuck over 15 minutes stops blocking, said in the log.
            $blocker = $event->subject_id === null ? null : AutomationEvent::withoutGlobalScopes()
                ->where('subject_type', $event->subject_type)->where('subject_id', $event->subject_id)
                ->whereNull('delivered_at')->whereNull('failed_at')
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->where(fn ($q) => $q->where('occurred_at', '<', $event->occurred_at)
                    ->orWhere(fn ($q) => $q->where('occurred_at', $event->occurred_at)->where('id', '<', $event->getKey())))
                ->orderBy('occurred_at')->first(['id', 'occurred_at', 'available_at']);
            if ($blocker !== null) {
                if (($blocker->available_at ?? $blocker->occurred_at)->gt(now()->subMinutes(15))) {
                    continue;
                }
                \Illuminate\Support\Facades\Log::warning("Automation event {$event->getKey()} sent past stuck earlier event {$blocker->getKey()} on the same record (due over 15 minutes).");
            }
            // Claimed before dispatch, so two overlapping sweeps cannot both
            // queue the same event.
            $claimed = AutomationEvent::withoutGlobalScopes()
                ->whereKey($event->getKey())
                ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', $lease))
                ->update(['queued_at' => now()]);

            if ($claimed === 1) {
                DeliverAutomationEvent::queueFor($event);
            }
        }

        $this->info($due->isEmpty()
            ? 'No automation events were due.'
            : "Queued {$due->count()} automation event(s).");

        return self::SUCCESS;
    }
}
