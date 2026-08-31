<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumnValue;
use App\Models\TaskPriorityOption;
use App\Models\TaskStatusOption;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;

final class TaskListQuery
{
    /**
     * Server-side cap on `crossBoardRows()`'s Calendar/Timeline result
     * (whole-branch review finding 4). Those two presentations never
     * paginate — `page`/`per_page` describe a page of a LIST, and a full
     * month grid or Gantt has no such concept, so `applyWindow()` returns
     * `$paginate = false` for both, the same as it does for a single
     * board's own Calendar/Timeline. For ONE board that is bounded by how
     * many tasks that board holds; cross-board it is bounded only by the
     * organization filter and the (already generous) 400-day window,
     * while every row drags in seven eager relations plus its subtask
     * tree — a cheap GET on a busy organization could otherwise
     * materialise tens of thousands of models into one response. Reported
     * truthfully via `omitted.capped` rather than silently truncated, the
     * same rule every other omission in this response already follows.
     */
    private const CROSS_BOARD_ROW_CAP = 1000;

    public function __construct(private readonly TaskBoardAccess $access, private readonly TaskViewCodec $codec) {}

    public function boardRows(User $user, ViewScope $scope, TaskBoard $board, array $configuration, int $page, int $perPage): array
    {
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $user, $scope)->select('task_boards.id');
        $query = Task::query()->where('board_id', $board->id)->whereIn('board_id', $visibleBoards)
            ->active()->whereNull('parent_task_id');
        $this->applyConfiguration($query, $board, $configuration);

        // The summary describes the complete filtered accessible result — computed
        // here, against the unwindowed query, before any window narrows what is
        // returned in `data`. A Kanban column header must show the true count for
        // its bucket across the whole board, not just what one slice returned.
        $summaryTotal = (clone $query)->reorder()->count();
        $groupCounts = $this->groupCounts($query, $board, $configuration['group_by']);
        $statusSummaries = (clone $query)->reorder()->selectRaw('status_option_id, COUNT(*) as aggregate')
            ->groupBy('status_option_id')->orderBy('status_option_id')->get()
            ->map(fn (Task $row) => ['status_option_id' => $row->status_option_id, 'count' => (int) $row->aggregate])
            ->values()->all();

        $presentation = TaskPresentation::from($configuration['presentation']);
        $window = TaskWindow::parse($presentation, $configuration['window']);
        [$windowed, $omitted, $paginate] = $this->applyWindow($query, $board, $presentation, $window, $summaryTotal);

        $total = $paginate ? $summaryTotal : (clone $windowed)->reorder()->count();
        $rowsQuery = (clone $windowed)->with($this->relations($presentation));
        $rows = $paginate ? $rowsQuery->forPage($page, $perPage)->get() : $rowsQuery->get();

        return [
            'data' => $rows->map(fn (Task $task) => $this->present($task, $configuration['group_by'], $presentation))->values()->all(),
            'total' => $total,
            'page' => $paginate ? $page : 1,
            'per_page' => $perPage,
            'last_page' => $paginate ? max(1, (int) ceil($total / $perPage)) : 1,
            'group_counts' => $groupCounts,
            'status_summaries' => $statusSummaries,
            'summary' => ['total' => $summaryTotal, 'group_counts' => $groupCounts, 'status_summaries' => $statusSummaries],
            'omitted' => $omitted,
            'window' => $window->toArray(),
            'fingerprint' => $this->boardFingerprint($query, $board),
        ];
    }

    /**
     * Narrows the pre-window filtered query down to what a presentation's window
     * actually shows, and counts what fell out along the way. Table keeps Phase 1's
     * exact paginated behaviour untouched — a frozen client omitting `window` must
     * see byte-identical results. Workload's per-owner reshaping is Task 7's job;
     * here it is left as the plain filtered-accessible set.
     *
     * `$board` is only dereferenced by the Kanban branch — a bucket field is
     * board-structure-specific, so `crossBoardRows()` (which never windows
     * Kanban) is the one caller that passes `null`.
     *
     * @return array{0:Builder,1:array{undated:int,outside_window:int,unowned:int},2:bool}
     */
    private function applyWindow(Builder $query, ?TaskBoard $board, TaskPresentation $presentation, TaskWindow $window, int $summaryTotal): array
    {
        $omitted = ['undated' => 0, 'outside_window' => 0, 'unowned' => 0, 'capped' => 0];
        $windowed = clone $query;

        if ($presentation === TaskPresentation::Calendar) {
            $omitted['undated'] = (clone $query)->reorder()->whereNull('due_on')->count();
            $windowed->whereNotNull('due_on')->whereBetween('due_on', [$window->from(), $window->to()]);
            $omitted['outside_window'] = $summaryTotal - $omitted['undated'] - (clone $windowed)->reorder()->count();

            return [$windowed, $omitted, false];
        }

        if ($presentation === TaskPresentation::Timeline) {
            $omitted['undated'] = (clone $query)->reorder()->whereNull('start_on')->whereNull('due_on')->count();
            // (start_on IS NULL OR start_on <= :to) AND (due_on IS NULL OR due_on >= :from),
            // with a task that carries neither date excluded rather than trivially matched.
            $windowed->where(fn (Builder $has) => $has->whereNotNull('start_on')->orWhereNotNull('due_on'))
                ->where(fn (Builder $started) => $started->whereNull('start_on')->orWhere('start_on', '<=', $window->to()))
                ->where(fn (Builder $due) => $due->whereNull('due_on')->orWhere('due_on', '>=', $window->from()));
            $omitted['outside_window'] = $summaryTotal - $omitted['undated'] - (clone $windowed)->reorder()->count();

            return [$windowed, $omitted, false];
        }

        if ($presentation === TaskPresentation::Kanban) {
            $this->applyFilter($windowed, $board, [
                'field' => $window->bucketField(), 'operator' => 'in', 'value' => $window->buckets(),
            ]);
            $omitted['outside_window'] = $summaryTotal - (clone $windowed)->reorder()->count();

            return [$windowed, $omitted, false];
        }

        if ($presentation === TaskPresentation::Workload) {
            // TaskWorkloadQuery (Task 7) reshapes `data` into per-owner rows
            // via its own bounded SQL aggregation and the controller
            // overwrites `data` unconditionally for this presentation — so
            // nothing this query fetches here is ever used. Short-circuit to
            // an empty result rather than pulling the board's complete
            // unwindowed task set (with every relation `relations()` eager
            // loads) into PHP only to throw it away; that fetch is exactly
            // what a 400-day window on a busy board would otherwise pay for.
            $windowed->whereRaw('0 = 1');

            return [$windowed, $omitted, false];
        }

        return [$windowed, $omitted, true];
    }

    public function myTasks(User $user, ViewScope $scope, int $page, int $perPage): array
    {
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $user, $scope);
        $query = Task::query()->active()->where('owner_user_id', $user->id)
            ->whereIn('board_id', (clone $visibleBoards)->select('task_boards.id'))
            ->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderBy('number');
        $total = (clone $query)->reorder()->count();
        $rows = (clone $query)->with([...$this->relations(TaskPresentation::Table), 'board:id,name'])
            ->forPage($page, $perPage)->get();

        return [
            'data' => $rows->map(function (Task $task): array {
                $row = $this->present($task, null, TaskPresentation::Table);
                $row['board'] = ['id' => $task->board->id, 'name' => $task->board->name];

                return $row;
            })->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'fingerprint' => $this->myTasksFingerprint($query, $visibleBoards),
        ];
    }

    /**
     * The same accessible-boards prefilter `myTasks()` uses
     * (`TaskBoardAccess::constrainVisible`), windowed by Calendar or
     * Timeline instead of filtered to one owner — Task 14's cross-board
     * summaries (spec: reachable the way My Tasks is, read-only). Workload
     * is not handled here: the caller (`MyTasksController`) short-circuits
     * `data` from `TaskWorkloadQuery::rows()` the same way
     * `TaskBoardRowsController` already does for a single board, and
     * `applyWindow()`'s own Workload branch below never fetches a real row
     * set for the same reason it doesn't for one board — it would be
     * thrown away. Kanban is refused by the controller before this is ever
     * called: a bucket field is board-structure-specific and has no
     * cross-board meaning.
     */
    public function crossBoardRows(User $user, ViewScope $scope, TaskPresentation $presentation, TaskWindow $window, int $page, int $perPage): array
    {
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $user, $scope);
        $query = Task::query()->active()->whereNull('parent_task_id')
            ->whereIn('board_id', (clone $visibleBoards)->select('task_boards.id'))
            ->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderBy('number');

        $summaryTotal = (clone $query)->reorder()->count();
        [$windowed, $omitted, $paginate] = $this->applyWindow($query, null, $presentation, $window, $summaryTotal);

        $total = $paginate ? $summaryTotal : (clone $windowed)->reorder()->count();
        $rowsQuery = (clone $windowed)->with([...$this->relations($presentation), 'board:id,name']);
        if ($paginate) {
            $rows = $rowsQuery->forPage($page, $perPage)->get();
        } else {
            $rows = $rowsQuery->limit(self::CROSS_BOARD_ROW_CAP)->get();
            $omitted['capped'] = max(0, $total - $rows->count());
        }

        return [
            'data' => $rows->map(function (Task $task) use ($presentation): array {
                $row = $this->present($task, null, $presentation);
                $row['board'] = ['id' => $task->board->id, 'name' => $task->board->name];

                return $row;
            })->values()->all(),
            'total' => $total,
            'page' => $paginate ? $page : 1,
            'per_page' => $perPage,
            'last_page' => $paginate ? max(1, (int) ceil($total / $perPage)) : 1,
            'omitted' => $omitted,
            'window' => $window->toArray(),
            'fingerprint' => $this->myTasksFingerprint($query, $visibleBoards),
        ];
    }

    private function applyConfiguration(Builder $query, TaskBoard $board, array $configuration): void
    {
        if ($configuration['search'] !== '') {
            $search = '%'.$this->like($configuration['search']).'%';
            $query->where(fn (Builder $match) => $match->where('title', 'like', $search)->orWhere('number', 'like', $search));
        }
        foreach ($configuration['filters'] as $filter) {
            $this->applyFilter($query, $board, $filter);
        }
        if ($configuration['sorts'] === []) {
            $query->orderBy('group_id')->orderBy('position')->orderBy('id');

            return;
        }
        foreach ($configuration['sorts'] as $sort) {
            $this->applySort($query, $board, $sort['field'], $sort['direction']);
        }
        $query->orderBy('id');
    }

    private function applyFilter(Builder $query, TaskBoard $board, array $filter): void
    {
        $field = $this->codec->field($board, $filter['field']);
        if (! $field['custom']) {
            $column = match ($filter['field']) {
                'task' => 'title', 'owner' => 'owner_user_id', 'status' => 'status_option_id',
                'priority' => 'priority_option_id', 'start_on' => 'start_on', 'due_on' => 'due_on',
            };
            $this->applyColumnFilter($query, $column, $filter['operator'], $filter['value']);

            return;
        }

        $relation = match ($field['type']) {
            'people' => 'columnPeople', 'label' => 'columnLabels', 'file' => 'columnFiles', default => 'columnValues',
        };
        $valueColumn = match ($field['type']) {
            'text' => 'text_value', 'number' => 'number_value', 'checkbox' => 'boolean_value',
            'date' => 'date_value', 'link' => 'url_value', 'people' => 'user_id',
            'label' => 'column_option_id', 'file' => 'attachment_id',
        };
        $operator = $filter['operator'];
        if ($operator === 'empty') {
            $query->whereDoesntHave($relation, fn (Builder $value) => $value
                ->where('column_definition_id', $field['id'])
                ->when($relation === 'columnValues', fn (Builder $scalar) => $scalar->whereNotNull($valueColumn)));

            return;
        }
        if ($operator === 'not_empty') {
            $query->whereHas($relation, fn (Builder $value) => $value
                ->where('column_definition_id', $field['id'])
                ->when($relation === 'columnValues', fn (Builder $scalar) => $scalar->whereNotNull($valueColumn)));

            return;
        }
        if ($operator === 'contains_all') {
            foreach ($filter['value'] as $member) {
                $query->whereHas($relation, fn (Builder $value) => $value
                    ->where('column_definition_id', $field['id'])->where($valueColumn, $member));
            }

            return;
        }
        if ($field['type'] === 'label' && $operator === 'not_in') {
            $query->whereDoesntHave($relation, fn (Builder $value) => $value
                ->where('column_definition_id', $field['id'])->whereIn($valueColumn, $filter['value']));

            return;
        }
        $query->whereHas($relation, function (Builder $value) use ($field, $filter, $valueColumn): void {
            $value->where('column_definition_id', $field['id']);
            $this->applyColumnFilter($value, $valueColumn, $filter['operator'], $filter['value']);
        });
    }

    private function applyColumnFilter(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'empty' => $query->whereNull($column), 'not_empty' => $query->whereNotNull($column),
            'equals', 'on' => $query->where($column, $value),
            'contains' => $query->where($column, 'like', '%'.$this->like((string) $value).'%'),
            'in', 'contains_any' => $query->whereIn($column, $value), 'not_in' => $query->whereNotIn($column, $value),
            'before' => $query->whereDate($column, '<', $value), 'after' => $query->whereDate($column, '>', $value),
            'between' => $query->whereBetween($column, $value),
            'contains_all' => $this->applySingleValueContainsAll($query, $column, $value),
        };
    }

    private function applySingleValueContainsAll(Builder $query, string $column, array $values): void
    {
        count(array_unique($values)) === 1 ? $query->where($column, $values[0]) : $query->whereRaw('0 = 1');
    }

    private function applySort(Builder $query, TaskBoard $board, string $reference, string $direction): void
    {
        $field = $this->codec->field($board, $reference);
        if (! $field['custom']) {
            $sort = match ($reference) {
                'task' => 'title', 'owner' => 'owner_user_id',
                'status' => TaskStatusOption::query()->select('position')->whereColumn('task_status_options.id', 'tasks.status_option_id'),
                'priority' => TaskPriorityOption::query()->select('position')->whereColumn('task_priority_options.id', 'tasks.priority_option_id'),
                'start_on' => 'start_on', 'due_on' => 'due_on',
            };
            $query->orderBy($sort, $direction);

            return;
        }
        $column = match ($field['type']) {
            'text' => 'text_value', 'number' => 'number_value', 'checkbox' => 'boolean_value',
            'date' => 'date_value', 'link' => 'url_value',
        };
        $query->orderBy(TaskColumnValue::query()->select($column)
            ->whereColumn('task_column_values.task_id', 'tasks.id')
            ->where('column_definition_id', $field['id'])->limit(1), $direction);
    }

    private function groupCounts(Builder $query, TaskBoard $board, ?string $groupBy): array
    {
        if ($groupBy === null) {
            $column = 'tasks.group_id';
            $grouped = (clone $query)->reorder()->selectRaw("{$column} as group_by_value, COUNT(*) as aggregate")
                ->groupBy($column)->orderBy($column)->get();
        } else {
            $field = $this->codec->field($board, $groupBy);
            if (! $field['custom']) {
                $column = match ($groupBy) {
                    'task' => 'tasks.title', 'owner' => 'tasks.owner_user_id', 'status' => 'tasks.status_option_id',
                    'priority' => 'tasks.priority_option_id', 'start_on' => 'tasks.start_on', 'due_on' => 'tasks.due_on',
                };
                $grouped = (clone $query)->reorder()->selectRaw("{$column} as group_by_value, COUNT(*) as aggregate")
                    ->groupBy($column)->orderBy($column)->get();
            } else {
                $column = match ($field['type']) {
                    'text' => 'text_value', 'number' => 'number_value', 'checkbox' => 'boolean_value',
                    'date' => 'date_value', 'link' => 'url_value',
                };
                $grouped = (clone $query)->reorder()->leftJoin('task_column_values as grouping_value', function ($join) use ($field): void {
                    $join->on('grouping_value.task_id', '=', 'tasks.id')->where('grouping_value.column_definition_id', '=', $field['id']);
                })->selectRaw("grouping_value.{$column} as group_by_value, COUNT(*) as aggregate")
                    ->groupBy("grouping_value.{$column}")->orderBy("grouping_value.{$column}")->get();
            }
        }

        return $grouped->map(fn ($row) => [
            'group_id' => $row->group_by_value, 'group_by_value' => $row->group_by_value, 'count' => (int) $row->aggregate,
        ])->values()->all();
    }

    private function present(Task $task, ?string $groupBy, TaskPresentation $presentation): array
    {
        $effectiveGroup = $task->group ?? $task->parent?->group;
        $custom = [];
        foreach ($task->columnValues as $value) {
            $custom[$value->column_definition_id] = $value->text_value ?? $value->number_value ?? $value->boolean_value
                ?? $value->date_value?->format('Y-m-d')
                ?? ($value->url_value === null ? null : ['url' => $value->url_value, 'label' => $value->link_label_value]);
        }
        foreach ($task->columnPeople->groupBy('column_definition_id') as $id => $values) {
            $custom[$id] = $values->pluck('user_id')->sort()->values()->all();
        }
        foreach ($task->columnLabels->groupBy('column_definition_id') as $id => $values) {
            $custom[$id] = $values->pluck('column_option_id')->sort()->values()->all();
        }
        foreach ($task->columnFiles->groupBy('column_definition_id') as $id => $values) {
            $custom[$id] = $values->pluck('attachment_id')->sort()->values()->all();
        }

        $row = [
            'id' => $task->id, 'board_id' => $task->board_id, 'group_id' => $effectiveGroup?->id,
            'parent_task_id' => $task->parent_task_id, 'number' => $task->number, 'title' => $task->title,
            'owner_user_id' => $task->owner_user_id,
            'collaborator_user_ids' => $task->collaborators->pluck('user_id')->values()->all(),
            'status_option_id' => $task->status_option_id, 'priority_option_id' => $task->priority_option_id,
            'start_on' => $task->start_on?->format('Y-m-d'), 'due_on' => $task->due_on?->format('Y-m-d'),
            'position' => (int) $task->position, 'version' => (int) $task->version,
            'detail_version' => (int) $task->detail_version, 'custom_values' => $custom,
            'group_by_value' => $this->groupValue($task, $groupBy, $custom),
            'subtasks' => $task->subtasks
                ->map(fn (Task $subtask) => $this->present($subtask, $groupBy, $presentation))->values()->all(),
        ];

        // Task 12 draws lines between Timeline bars and cannot fetch a detail
        // response for every row it renders, so this rides on the row here —
        // omitted elsewhere because a Kanban card (etc.) has nowhere to draw
        // one and the payload would be dead weight (task-4 brief).
        if ($presentation === TaskPresentation::Timeline) {
            $row['blocked_by'] = $task->blockedByEdges->map(fn ($edge) => [
                'id' => $edge->id,
                'task_id' => $edge->blocker_task_id,
                'number' => $edge->blocker?->number,
                'title' => $edge->blocker?->title,
            ])->values()->all();
        }

        return $row;
    }

    private function groupValue(Task $task, ?string $groupBy, array $custom): mixed
    {
        return match ($groupBy) {
            null => $task->group_id ?? $task->parent?->group_id, 'task' => $task->title,
            'owner' => $task->owner_user_id, 'status' => $task->status_option_id,
            'priority' => $task->priority_option_id, 'start_on' => $task->start_on?->format('Y-m-d'),
            'due_on' => $task->due_on?->format('Y-m-d'), default => $custom[substr($groupBy, 7)] ?? null,
        };
    }

    private function boardFingerprint(Builder $query, TaskBoard $board): string
    {
        $parents = (clone $query)->reorder()->select('tasks.id');

        return hash('sha256', json_encode([
            (int) $board->structure_version, $this->versionAggregate($query),
            $this->versionAggregate(Task::query()->active()->whereIn('parent_task_id', $parents)),
        ], JSON_THROW_ON_ERROR));
    }

    private function myTasksFingerprint(Builder $query, Builder $visibleBoards): string
    {
        $parents = (clone $query)->reorder()->select('tasks.id');
        $boards = (clone $visibleBoards)->reorder()->orderBy('task_boards.id')
            ->get(['task_boards.id', 'task_boards.name', 'task_boards.structure_version'])
            ->map(fn (TaskBoard $board) => [$board->id, $board->name, (int) $board->structure_version])
            ->all();

        return hash('sha256', json_encode([
            $this->versionAggregate($query),
            $this->versionAggregate(Task::query()->active()->whereIn('parent_task_id', $parents)),
            $boards,
        ], JSON_THROW_ON_ERROR));
    }

    private function versionAggregate(Builder $query): array
    {
        $row = (clone $query)->reorder()->selectRaw(
            'COUNT(*) as aggregate_count, COALESCE(SUM(version), 0) as aggregate_version, '
            .'COALESCE(SUM(detail_version), 0) as aggregate_detail_version, MAX(updated_at) as aggregate_updated'
        )->first();

        return [(int) $row->aggregate_count, (int) $row->aggregate_version,
            (int) $row->aggregate_detail_version, (string) $row->aggregate_updated];
    }

    private function relations(TaskPresentation $presentation): array
    {
        $values = ['group', 'parent.group', 'collaborators', 'columnValues', 'columnPeople', 'columnLabels', 'columnFiles'];
        if ($presentation === TaskPresentation::Timeline) {
            $values[] = 'blockedByEdges.blocker';
        }

        return [...$values, 'subtasks' => fn ($query) => $query->active()->orderBy('position')->with($values)];
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
