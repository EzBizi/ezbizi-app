<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
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
            ->where('occurred_at', '>=', $since)->get(['organization_id', 'action', 'metadata']);

        foreach ($rows->groupBy('organization_id') as $orgId => $events) {
            $org = Organization::query()->withoutGlobalScopes()->find($orgId);
            if ($org === null) {
                continue;
            }
            $moved = $events->where('action', 'task.board_rule.moved')->count();
            $flagged = $events->count() - $moved;
            $names = User::query()->withoutGlobalScopes()->where('organization_id', $orgId)
                ->whereKey($events->pluck('metadata.creator_id')->filter()->unique())->pluck('name', 'id');
            $people = $events->groupBy(fn ($e) => $names[$e->metadata['creator_id'] ?? ''] ?? 'Monday or a rule')
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
