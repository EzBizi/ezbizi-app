<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class TasksGlance extends DescribedGlance
{
    protected const KEY = 'tasks';
    protected const LABEL = 'Tasks';
    protected const PERMISSION = 'tasks.view';
    protected const PRESENTATIONS = ['bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'bar';
    protected const SNAPSHOT = false;

    public function __construct(private readonly TaskBoardAccess $access) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period): GlancePayload {
            $boards = $this->access->constrainVisible(
                TaskBoard::query()->active(),
                $user,
                ViewScope::forUser($user),
            );
            $tasks = Task::query()->active()->whereIn('board_id', $boards->select('id'));
            $open = $this->open(clone $tasks);
            $fromDate = $period->startsOn->toDateString();
            $toDate = $period->endsOn->toDateString();
            $today = Carbon::now($period->timezone)->toDateString();

            $counts = [
                'open' => (clone $open)->count(),
                'assigned_to_me' => (clone $open)->where('owner_user_id', $user->getKey())->count(),
                'due_in_period' => (clone $open)->whereBetween('due_on', [$fromDate, $toDate])->count(),
                'overdue' => (clone $open)->whereNotNull('due_on')->whereDate('due_on', '<', $today)->count(),
                'blocked' => (clone $tasks)->whereHas('statusOption', fn (Builder $status) => $status
                    ->where('category', StatusCategory::Blocked->value))->count(),
                'completed_in_period' => (clone $tasks)
                    ->whereHas('statusOption', fn (Builder $status) => $status
                        ->where('category', StatusCategory::Completed->value))
                    ->whereBetween('completed_at', [
                        $period->startsOn->copy()->utc(),
                        $period->endsOn->copy()->utc(),
                    ])->count(),
            ];

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: [
                    'empty' => array_sum($counts) === 0,
                    'counts' => $counts,
                    'top_owners' => $this->topOwners($open),
                ],
            );
        });
    }

    private function open(Builder $tasks): Builder
    {
        return $tasks->whereHas('statusOption', fn (Builder $status) => $status->whereNotIn('category', [
            StatusCategory::Completed->value,
            StatusCategory::Cancelled->value,
        ]));
    }

    /** @return list<array{id:string,name:string,open:int}> */
    private function topOwners(Builder $open): array
    {
        $counts = (clone $open)
            ->whereNotNull('owner_user_id')
            ->selectRaw('owner_user_id, COUNT(*) AS open_count')
            ->groupBy('owner_user_id')
            ->orderByDesc('open_count')
            ->limit(5)
            ->get();
        $names = User::query()->whereIn('id', $counts->pluck('owner_user_id'))->pluck('name', 'id');

        return $counts->map(fn (Task $row): array => [
            'id' => $row->owner_user_id,
            'name' => (string) ($names[$row->owner_user_id] ?? 'Unavailable user'),
            'open' => (int) $row->getAttribute('open_count'),
        ])->all();
    }
}
