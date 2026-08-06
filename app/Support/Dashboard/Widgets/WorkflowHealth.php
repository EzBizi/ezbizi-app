<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\User;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Support\Dashboard\Widget;

/**
 * Whether the automations are still working.
 *
 * ── Why this belongs on a dashboard at all ───────────────────────
 *
 * Automations fail the way everything else in this system fails: silently. A
 * rule that stopped firing looks identical to a rule with nothing to do, and the
 * person who configured it is the last to find out — usually from a customer who
 * never got their reminder.
 *
 * There is a staff Health screen that watches the outbox platform-wide, but that
 * is for us. This is the same question asked on behalf of one organization, by
 * somebody who can actually fix the rule.
 */
class WorkflowHealth implements Widget
{
    public function key(): string { return 'workflows.health'; }

    public function label(): string { return 'Automation health'; }

    public function permission(): string { return 'workflows.view'; }

    public function options(User $user): array
    {
        return [
            'days' => [
                'label'   => 'Period',
                'type'    => 'enum',
                'options' => [1, 7, 30],
                'default' => 7,
            ],
        ];
    }

    public function resolve(User $user, array $options): array
    {
        $since = now()->subDays(max(1, min((int) ($options['days'] ?? 7), 30)));

        // No reach filter: a workflow belongs to the organization and is scoped
        // by the rule's own configuration, not by the reader's assignments.
        // Permission to view workflows is the whole gate here (§4).
        $runs = WorkflowRun::query()->where('started_at', '>=', $since);

        $failed = (clone $runs)->whereNotNull('failed_at')->count();
        $total  = $runs->count();

        $waiting = WorkflowEvent::query()->whereNull('delivered_at')->count();

        return [
            'runs'    => $total,
            'failed'  => $failed,
            'waiting' => $waiting,

            // Stated rather than left to the client to infer. "0 failures" out
            // of 0 runs is not health, it is silence, and the two should not
            // render the same way.
            'idle'    => $total === 0,
        ];
    }
}
