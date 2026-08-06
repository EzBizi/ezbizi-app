<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWorkflowEvent;
use App\Models\WorkflowEvent;
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
class DispatchWorkflowEvents extends Command
{
    protected $signature = 'ezbizi:dispatch-workflow-events {--limit=500}';

    protected $description = 'Queue delivery for workflow events that are due';

    public function handle(): int
    {
        $due = WorkflowEvent::withoutGlobalScopes()
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('available_at', '<=', now())
            ->where('attempts', '<', WorkflowEvent::MAX_ATTEMPTS)
            ->orderBy('occurred_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $event) {
            DeliverWorkflowEvent::dispatch($event->getKey());
        }

        $this->info($due->isEmpty()
            ? 'No workflow events were due.'
            : "Queued {$due->count()} workflow event(s).");

        return self::SUCCESS;
    }
}
