<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskQuickSearchController extends TaskApiController
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['required', 'string', 'min:2', 'max:191']]);
        $scope = ViewScope::forUser($request->user());
        $this->access->directoryDecision($request->user(), $scope)->authorize();
        $boards = $this->access->constrainVisible(TaskBoard::query()->active(), $request->user(), $scope)
            ->select('task_boards.id');
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($input['q'])).'%';
        $tasks = Task::query()->active()->whereIn('board_id', $boards)
            ->where(fn (Builder $match) => $match
                ->where('number', 'like', $needle)
                ->orWhere('title', 'like', $needle)
                ->orWhereHas('board', fn (Builder $board) => $board->where('name', 'like', $needle)))
            ->with('board:id,name')->orderBy('number')->limit(20)->get();

        return response()->json(['data' => $tasks->map(fn (Task $task) => [
            'board_id' => $task->board_id,
            'board_name' => $task->board->name,
            'task_id' => $task->id,
            'number' => $task->number,
            'title' => $task->title,
            'deep_link' => "/tasks/boards/{$task->board_id}/tasks/{$task->id}",
        ])->values()]);
    }
}
