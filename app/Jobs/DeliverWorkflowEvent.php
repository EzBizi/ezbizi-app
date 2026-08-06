<?php

namespace App\Jobs;

use App\Models\WorkflowEvent;
use App\Support\Workflows\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivering one outbox event (plan §5.5).
 *
 * On a queue, never inline — a slow external webhook must not delay a user's
 * save. Retries with backoff, and a permanent failure dead-letters the event
 * rather than retrying forever.
 */
class DeliverWorkflowEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = WorkflowEvent::MAX_ATTEMPTS;

    /**
     * Backoff in seconds.
     *
     * Widening, because the usual reason a webhook fails is that the far end is
     * having a bad time — and hammering it every second makes that worse for
     * everybody including us.
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $eventId) {}

    /**
     * One delivery attempt per event, whatever else is queued.
     *
     * The run table's unique index already makes a double delivery harmless, but
     * two workers racing the same event would both call the same webhook before
     * either wrote a run — and the far end would see it twice.
     */
    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(WorkflowEngine $engine): void
    {
        $event = WorkflowEvent::withoutGlobalScopes()->find($this->eventId);

        if ($event === null || $event->delivered_at !== null) {
            return;   // already handled, or the organization was removed
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        $engine->deliver($event);
    }

    /**
     * Out of retries.
     *
     * The event is marked failed rather than deleted: §5.5 asks for a
     * dead-letter, and an event that vanishes takes with it the only evidence
     * that an automation was supposed to happen.
     */
    public function failed(Throwable $e): void
    {
        WorkflowEvent::withoutGlobalScopes()
            ->whereKey($this->eventId)
            ->update(['failed_at' => now(), 'last_error' => $e->getMessage()]);
    }
}
