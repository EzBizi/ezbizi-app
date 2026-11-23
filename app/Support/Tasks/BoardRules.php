<?php

namespace App\Support\Tasks;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Management's board rules (Jose approved 2026-11-17). On each branch board
 * the "boards" are groups named by role: Administration, General Manager,
 * Accounting, Field Manager, Customer Relations, Project Manager.
 *
 * - Every refund starts in Customer Relations; Accounting takes APPROVED
 *   refunds only, and an approved refund in Customer Relations moves there.
 * - Disputes, chargebacks, claims and evidence go to the General Manager.
 * - Deposit jobs go to the Project Manager, where the branch has one.
 *
 * A clear case on the wrong group moves to the right group on the same board
 * and its creator is told; with no such group, or Accounting holding
 * something that is not an approved refund, it is flagged instead. Each
 * outcome is an audit event (task.board_rule.moved / .flagged), which the
 * misfiled report counts.
 */
class BoardRules
{
    public const ROLES = [
        'admin' => 'Administration',
        'gm' => 'General Manager',
        'accounting' => 'Accounting',
        'field' => 'Field Manager',
        'cr' => 'Customer Relations',
        'pm' => 'Project Manager',
    ];

    /** What each role's group is for, shown under its heading. */
    public const PURPOSE = [
        'admin' => 'Administration: office and company matters.',
        'gm' => 'Disputes, chargebacks, claims and evidence.',
        'accounting' => 'Approved refunds only.',
        'field' => 'Technicians, ETAs, rescheduling and dispatch. Never refunds or disputes.',
        'cr' => 'Every refund starts here.',
        'pm' => 'Deposit jobs.',
    ];

    private static bool $running = false;

    /** The roles a group's name says it is: "General Manager / Project Manager - Ron" is both. */
    public static function rolesOf(string $name): array
    {
        $n = mb_strtolower($name);
        $roles = [];
        foreach (['admin' => 'administration', 'gm' => 'general manager', 'accounting' => 'accounting',
            'field' => 'field manager', 'cr' => 'customer relations', 'pm' => 'project manager'] as $role => $words) {
            if (str_contains($n, $words)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** dispute, refund, deposit or null, from the words in the task. */
    public static function kindOf(string $text): ?string
    {
        // ponytail: keywords; a classifier can replace this one function.
        return match (true) {
            (bool) preg_match('/charge\s?back|dispute|\bclaims?\b|evidence/i', $text) => 'dispute',
            (bool) preg_match('/refund/i', $text) => 'refund',
            (bool) preg_match('/deposit/i', $text) => 'deposit',
            default => null,
        };
    }

    public function check(Task $task): ?string
    {
        if (self::$running || $task->parent_task_id !== null || $task->sensitive || $task->completed_at !== null) {
            return null;
        }
        $org = Organization::query()->withoutGlobalScopes()->find($task->organization_id);

        return $org === null ? null : app(TenantContext::class)->run($org, function () use ($task) {
            self::$running = true;
            try {
                return $this->apply($task->fresh(['group', 'board', 'statusRow']) ?? $task);
            } finally {
                self::$running = false;
            }
        });
    }

    private function apply(Task $task): ?string
    {
        $groups = TaskGroup::query()->where('board_id', $task->board_id)->orderBy('position')->get();
        /** @var Collection<string, TaskGroup> $byRole first group per role */
        $byRole = collect();
        foreach ($groups as $g) {
            foreach (self::rolesOf($g->name) as $r) {
                $byRole->has($r) || $byRole->put($r, $g);
            }
        }
        // Only a branch board laid out by roles.
        if ($byRole->count() < 2 || $task->group === null) {
            return null;
        }
        $here = self::rolesOf($task->group->name);
        if ($here === []) {
            return null;
        }

        $text = $task->title.' '.TaskColumnValue::query()->where('task_id', $task->id)->whereNotNull('value_text')->pluck('value_text')->implode(' ');
        $kind = self::kindOf($text);
        $approved = mb_strtolower(trim((string) $task->statusRow?->name)) === 'approved';
        // A complaint or bad-review rule's task in Customer Relations stays there while it is as the
        // rule made it (desk 11-18: every refund starts in Customer Relations; 17 were moved off on
        // 11-18). Once a person marks it Approved, the Accounting move runs as usual.
        if ($task->created_by_automation_id !== null && in_array('cr', $here, true) && ! $approved) {
            return null;
        }

        $target = match ($kind) {
            'dispute' => 'gm',
            'refund' => $approved ? 'accounting' : 'cr',
            'deposit' => 'pm',
            default => null,
        };

        if ($target === null) {
            // Accounting takes approved refunds only.
            return in_array('accounting', $here, true)
                ? $this->flag($task, 'Accounting takes approved refunds only; this does not look like one.')
                : null;
        }
        if (in_array($target, $here, true)) {
            return null;
        }
        $group = $byRole->get($target);
        if ($group === null) {
            $where = $target === 'pm' ? "the {$task->board?->name} Project Manager board" : self::ROLES[$target];

            return $this->flag($task, "Belongs on {$where}, which this board does not have.");
        }
        $why = match ($kind) {
            'dispute' => 'disputes and chargebacks go to the General Manager',
            'refund' => $target === 'accounting' ? 'approved refunds go to Accounting' : 'refunds start there',
            default => 'deposit jobs go to the Project Manager',
        };
        if (! config('ezbizi.task_board_moves', true)) {
            return $this->flag($task, 'Belongs in '.self::ROLES[$target].": {$why}.");
        }

        $from = $task->group->name;
        app(TaskWriter::class)->update($task, ['group_id' => $group->id], null);
        $this->record('task.board_rule.moved', $task, ['from' => $from, 'to' => $group->name, 'kind' => $kind]);
        $this->tell($task, 'Moved to '.self::ROLES[$target].": {$why}");

        return 'moved';
    }

    private function flag(Task $task, string $why): string
    {
        $this->record('task.board_rule.flagged', $task, ['why' => $why, 'group' => $task->group?->name]);
        $this->tell($task, 'Check where this goes: '.$why);

        return 'flagged';
    }

    private function record(string $action, Task $task, array $metadata): void
    {
        app(AuditLogger::class)->log($action, $task, metadata: $metadata + ['creator_id' => $task->created_by_user_id]);
    }

    private function tell(Task $task, string $title): void
    {
        $creator = $task->created_by_user_id ? User::query()->find($task->created_by_user_id) : null;
        if ($creator !== null) {
            app(NotificationDispatcher::class)->dispatch(title: $title, body: $task->title, subject: $task, onlyTo: $creator, kind: 'task.moved');
        }
    }
}
