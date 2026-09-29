<?php

namespace App\Support\Automations;

use App\Models\Automation;

/**
 * Which rules send a given report — for the report lists to say "sent
 * automatically" (UI/UX queue 33, 2026-09-29).
 *
 * The ACTIVE version only: an older version's step is history, not what runs.
 * Switched-off and auto-disabled rules are returned with those flags rather
 * than dropped, because "a rule sends this, but it is off" is the thing
 * somebody looking at the report needs to know.
 *
 * Rule names and schedules are Automations' data: callers show the list only
 * to a reader holding `automations.view`, and otherwise say only whether one
 * exists. Tenant-scoped by the ordinary scope.
 */
final class ReportSenders
{
    /** Action types that send a report, and the parameter naming it. */
    public const REPORT_SAVED = 'report.send';

    public const REPORT_WORK_HOURS = 'send_report';

    /**
     * @param  list<string>  $reportIds
     * @return array<string, list<array{id: string, name: string, schedule: ?array, next_run_at: ?string, is_active: bool, disabled_at: ?string}>>
     *         keyed by report id; a report no rule sends is absent
     */
    public static function for(string $type, array $reportIds): array
    {
        $wanted = array_flip(array_filter($reportIds, fn ($id) => is_string($id) && $id !== ''));

        if ($wanted === []) {
            return [];
        }

        $senders = [];

        // ponytail: filtered in PHP over every rule in the tenant; a JSON
        // index on the action type when an organization has thousands of rules.
        foreach (Automation::query()->whereNotNull('active_version_id')->with('activeVersion')->get() as $rule) {
            $sent = [];

            foreach ((array) ($rule->activeVersion?->actions ?? []) as $action) {
                $id = (string) ($action['report_id'] ?? '');

                if (($action['type'] ?? null) === $type && isset($wanted[$id])) {
                    $sent[$id] = true;
                }
            }

            foreach (array_keys($sent) as $id) {
                $senders[$id][] = [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'schedule' => $rule->schedule,
                    'next_run_at' => $rule->next_run_at?->toIso8601String(),
                    'is_active' => (bool) $rule->is_active,
                    'disabled_at' => $rule->disabled_at?->toIso8601String(),
                ];
            }
        }

        return $senders;
    }
}
