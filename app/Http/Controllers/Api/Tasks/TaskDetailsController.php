<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskDetailsController extends TaskApiController
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    public function show(Request $request, TaskBoard $board, string $taskId): Response
    {
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $request->user(), $scope)
            ->select('task_boards.id');
        $task = Task::query()->where('board_id', $board->id)->whereIn('board_id', $visibleBoards)->active()->with([
            'board', 'group', 'parent.group', 'owner', 'statusOption', 'priorityOption',
            'collaborators.user', 'columnValues', 'columnPeople', 'columnLabels', 'columnFiles',
            'activities' => fn ($query) => $query->latest('created_at')->limit(200),
            'subtasks' => fn ($query) => $query->active()->orderBy('position'),
        ])->findOrFail($taskId);
        $etag = hash('sha256', json_encode([
            $task->id, (int) $task->version, (int) $task->detail_version,
            $task->subtasks->map(fn (Task $subtask) => [
                $subtask->id, (int) $subtask->version, (int) $subtask->detail_version,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
        $data = $this->presentTask($task);
        $data['custom_values'] = $this->customValues($task);
        $data['activities'] = $task->activities->map(fn ($activity) => [
            'id' => $activity->id,
            'actor_user_id' => $activity->actor_user_id,
            'kind' => $activity->kind,
            'summary' => $activity->summary,
            'data' => $activity->data,
            'created_at' => $activity->created_at?->toIso8601String(),
        ])->values();

        $response = response()->json(['data' => $data])->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }

    private function customValues(Task $task): array
    {
        $custom = [];
        foreach ($task->columnValues as $value) {
            $custom[$value->column_definition_id] = $value->text_value
                ?? $value->number_value
                ?? $value->boolean_value
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

        return $custom;
    }
}
