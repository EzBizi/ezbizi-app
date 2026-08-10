<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\User;
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
class AutomationHealth implements Widget
{
    public function key(): string { return 'automations.health'; }

    public function label(): string { return 'Automation health'; }

    public function permission(): string { return 'automations.view'; }

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

        // No reach filter: an automation belongs to the organization and is
        // scoped by the rule's own configuration, not by the reader's own.
        // Permission to view automations is the whole gate here (§4).
        $runs = AutomationRun::query()->where('started_at', '>=', $since);

        // `status`, not a failed_at timestamp — a run records its outcome as a
        // word (matched / skipped / succeeded / failed) so the trace can say
        // "the conditions did not match" rather than only "nothing happened".
        $failed = (clone $runs)->where('status', 'failed')->count();
        $total  = $runs->count();

        $waiting = AutomationEvent::query()->whereNull('delivered_at')->count();

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
