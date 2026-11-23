<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The board rules' week (Jose 11-17): per organization, how many items were
 * moved and flagged, by whoever made them, as a bell to the Administrators.
 */
class TasksMisfiledReport extends Command
{
    protected $signature = 'ezbizi:tasks-misfiled-report {--days=7} {--dry-run : Print only, no bells}';

    protected $description = 'Tell Administrators which Task Board items were misfiled, by person';

    public function handle(TenantContext $tenant): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $rows = AuditEvent::query()->withoutGlobalScopes()
            ->whereIn('action', ['task.board_rule.moved', 'task.board_rule.flagged'])
            ->where('occurred_at', '>=', $since)->get(['organization_id', 'action', 'metadata', 'target_id']);

        foreach ($rows->groupBy('organization_id') as $orgId => $events) {
            $org = Organization::query()->withoutGlobalScopes()->find($orgId);
            if ($org === null) {
                continue;
            }
            $moved = $events->where('action', 'task.board_rule.moved')->count();
            $flagged = $events->count() - $moved;
            // By the task's real author (desk 11-18: rule-made tasks carry the rule maker as creator):
            // a rule is named as the rule, a system command as EzBizi, and only a person as a person.
            $tasks = Task::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', $events->pluck('target_id')->filter()->unique())
                ->get(['id', 'created_by_user_id', 'created_by_automation_id', 'created_by_system', 'external_ref'])->keyBy('id');
            $rules = Automation::query()->withoutGlobalScopes()->whereIn('id', $tasks->pluck('created_by_automation_id')->filter()->unique())->pluck('name', 'id');
            $names = User::query()->withoutGlobalScopes()->where('organization_id', $orgId)
                ->whereKey($tasks->pluck('created_by_user_id')->filter()->unique())->pluck('name', 'id');
            $who = function ($e) use ($tasks, $rules, $names): string {
                $t = $tasks[$e->target_id] ?? null;

                return match (true) {
                    $t === null => 'unknown',
                    $t->created_by_automation_id !== null => 'EzBizi rule "'.($rules[$t->created_by_automation_id] ?? 'deleted rule').'"',
                    $t->created_by_system !== null => 'EzBizi (automation)',
                    str_starts_with((string) $t->external_ref, 'monday:') && $t->created_by_user_id === null => 'Monday',
                    default => $names[$t->created_by_user_id] ?? 'Monday',
                };
            };
            $people = $events->groupBy($who)
                ->map(fn ($e) => $e->count())->sortDesc()
                ->map(fn ($n, $who) => "{$who} {$n}")->implode(', ');
            $title = "Misfiled Task Board items: {$moved} moved, {$flagged} flagged";
            $this->line("{$org->name}: {$title}. {$people}");

            if (! $this->option('dry-run')) {
                $tenant->run($org, fn () => app(NotificationDispatcher::class)->dispatch(
                    title: $title, body: 'By who made them: '.$people,
                    roleIds: Role::query()->where('name', Role::ADMINISTRATOR)->pluck('id')->all(),
                ));
            }
        }

        return self::SUCCESS;
    }
}
