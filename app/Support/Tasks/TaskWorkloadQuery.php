<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Per-owner task counts per day or week over a `TaskWindow`-bounded range —
 * the Workload presentation's `data` (spec §5).
 *
 * COUNTS TASKS, not hours. There is no effort field and no capacity model;
 * this class never invents one. The rule, exactly:
 *
 *   - A task with BOTH `start_on` and `due_on` counts on every unit its span
 *     covers, so a fortnight-long task reads as sustained load, not a spike
 *     on its last day.
 *   - A task with only `due_on` counts on that date alone.
 *   - A task with no `due_on` at all — including one that carries only
 *     `start_on` — cannot be placed on a bounded timeline and is excluded,
 *     the same way Calendar excludes an undated task.
 *
 * Everything is aggregated in SQL. The window is capped at 400 days
 * (`TaskWindow`), so a busy board's 400-day request still runs as one bounded
 * query rather than pulling every task row into PHP to count by hand.
 */
final class TaskWorkloadQuery
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    /**
     * @return array{data:list<array{owner_id:?string,owner_name:?string,units:list<array{date:string,count:int}>}>,omitted:array{unowned:int}}
     */
    public function rows(User $user, ViewScope $scope, TaskBoard $board, TaskWindow $window): array
    {
        $base = fn (): Builder => $this->baseQuery($user, $scope, $board, $window);

        // A distinct-task count, independent of how many units its span
        // touches — the number of unassigned tasks the window surfaces, not
        // the number of unassigned owner-day cells.
        $unowned = (clone $base())->whereNull('owner_user_id')->count();

        $days = $this->daysBetween($window->from(), $window->to());
        if ($days === []) {
            return ['data' => [], 'omitted' => ['unowned' => $unowned]];
        }

        $weekStartsAt = $window->unit() === 'week' ? $this->weekStartsAt($scope) : 0;
        $covered = (clone $base())
            ->joinSub($this->daysSubquery($days), 'window_days', function (JoinClause $join): void {
                $join->whereRaw(
                    '`window_days`.`day` between coalesce(`tasks`.`start_on`, `tasks`.`due_on`) and `tasks`.`due_on`'
                );
            })
            ->distinct()
            ->selectRaw(
                $window->unit() === 'week'
                    ? 'tasks.owner_user_id as owner_user_id, tasks.id as task_id, '
                        .'date_sub(window_days.day, interval mod((dayofweek(window_days.day) - 1 - ? + 7), 7) day) as bucket'
                    : 'tasks.owner_user_id as owner_user_id, tasks.id as task_id, window_days.day as bucket',
                $window->unit() === 'week' ? [$weekStartsAt] : []
            );

        $aggregated = DB::query()->fromSub($covered, 'covered')
            ->selectRaw('owner_user_id, bucket, count(*) as aggregate')
            ->groupBy('owner_user_id', 'bucket')
            ->orderByRaw('owner_user_id is null')
            ->orderBy('owner_user_id')
            ->orderBy('bucket')
            ->get();

        return ['data' => $this->present($aggregated), 'omitted' => ['unowned' => $unowned]];
    }

    private function baseQuery(User $user, ViewScope $scope, TaskBoard $board, TaskWindow $window): Builder
    {
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $user, $scope)
            ->select('task_boards.id');

        // Only a task with a due date can be placed on a bounded timeline at
        // all (per the counting rule above), so `due_on IS NULL` is excluded
        // up front — the same anchor Calendar uses. The overlap check prunes
        // every task whose covered span (start_on, if any, through due_on)
        // never touches the window before the day-join even runs.
        return Task::query()
            ->where('board_id', $board->id)
            ->whereIn('board_id', $visibleBoards)
            ->active()
            ->whereNull('parent_task_id')
            ->whereNull('completed_at')
            ->whereNotNull('due_on')
            ->whereRaw('coalesce(start_on, due_on) <= ?', [$window->to()])
            ->where('due_on', '>=', $window->from());
    }

    /** @param list<string> $days */
    private function daysSubquery(array $days): string
    {
        return implode(' union all ', array_map(fn (string $day) => "select '{$day}' as day", $days));
    }

    /** @return list<string> */
    private function daysBetween(string $from, string $to): array
    {
        $cursor = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        $days = [];
        while ($cursor <= $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function weekStartsAt(ViewScope $scope): int
    {
        $startsOn = app(SettingsResolver::class)->get('week_starts_on', $scope->settingsScope());

        return $startsOn === 'monday' ? 1 : 0;
    }

    /**
     * @param \Illuminate\Support\Collection<int,object{owner_user_id:?string,bucket:string,aggregate:int}> $aggregated
     * @return list<array{owner_id:?string,owner_name:?string,units:list<array{date:string,count:int}>}>
     */
    private function present($aggregated): array
    {
        $ownerIds = $aggregated->pluck('owner_user_id')->filter()->unique()->values();
        $names = $ownerIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $ownerIds)->pluck('name', 'id');

        return $aggregated->groupBy('owner_user_id')->map(fn ($rows, $ownerId) => [
            'owner_id' => $ownerId === '' ? null : $ownerId,
            'owner_name' => $ownerId === '' ? null : ($names[$ownerId] ?? null),
            'units' => $rows->map(fn ($row) => [
                'date' => (string) $row->bucket,
                'count' => (int) $row->aggregate,
            ])->values()->all(),
        ])->values()->all();
    }
}
