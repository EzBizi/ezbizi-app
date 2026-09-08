<?php

namespace App\Jobs;

use App\Models\AutomationEvent;
use App\Support\Automations\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivering one outbox event (plan §5.5).
 *
 * On a queue, never inline — a slow external webhook must not delay a user's
 * save. The engine persists action retries in the outbox; queue backoff below
 * handles unexpected worker failures. Both paths have a bounded attempt budget.
 */
class DeliverAutomationEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = AutomationEvent::MAX_ATTEMPTS;

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
     * ShouldBeUnique is what makes this real. Declaring uniqueId() alone did
     * nothing at all — Laravel only takes the lock for jobs implementing that
     * interface, so the protection this docblock described did not exist.
     *
     * Belt to the run table's braces: the run row is claimed before any action
     * runs, so a race is already harmless. This stops two workers doing the
     * duplicated work at all.
     */
    public int $uniqueFor = 1200;

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(AutomationEngine $engine): void
    {
        $event = AutomationEvent::withoutGlobalScopes()->find($this->eventId);

        if ($event === null || $event->delivered_at !== null) {
            return;   // already handled, or the organization was removed
        }

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
        app(AutomationEngine::class)->failDelivery($this->eventId, $e->getMessage());
    }
}
