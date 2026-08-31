<?php

namespace App\Http\Middleware;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnOption;
use App\Models\TaskDependency;
use App\Models\TaskFolder;
use App\Models\TaskGroup;
use App\Models\TaskPriorityOption;
use App\Models\TaskRecordLink;
use App\Models\TaskStatusOption;
use App\Models\TaskUpdate;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskFolders;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prove the caller may know a bound Tasks resource exists before a controller
 * validates attacker-controlled fields or resolves body-supplied candidates.
 * Writers repeat the action-specific decision under lock; this edge check owns
 * the non-enumerating HTTP order and nested-resource relationship checks.
 */
final class EnsureTaskRouteAccess
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskFolders $folders,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $actor = $request->user();
        abort_unless($route !== null && $actor !== null, Response::HTTP_UNAUTHORIZED);

        $scope = ViewScope::forUser($actor);
        $board = $route->parameter('board');
        $task = $route->parameter('task');
        $folder = $route->parameter('folder');
        $dependency = $route->parameter('dependency');
        $action = $route->getActionName();

        if ($task instanceof Task) {
            $taskBoard = TaskBoard::query()->find($task->board_id);
            abort_unless($taskBoard !== null, Response::HTTP_NOT_FOUND);
            $this->access->viewDecision($actor, $taskBoard, $scope)->authorize();
            $board = $taskBoard;
        } elseif ($board instanceof TaskBoard) {
            if (str_ends_with($action, '\\TaskBoardRecoveryController@store')) {
                $this->access->recoveryAtCurrentViewDecision($actor, $board, $scope)->authorize();
            } elseif (str_ends_with($action, '\\TaskParticipantsController@index')
                && $request->query('purpose') === 'membership') {
                $view = $this->access->viewDecision($actor, $board, $scope);
                if ($view->denied()) {
                    if (! $this->access->canBrowseRecoverable($actor, $scope)) {
                        $view->authorize();
                    }
                    $this->access->recoveryAtCurrentViewDecision($actor, $board, $scope)->authorize();
                }
            } elseif (str_ends_with($action, '\\TaskBoardsController@duplicate')
                || str_ends_with($action, '\\TaskBoardsController@duplicateSource')) {
                $this->access->manageAtFiledPlaceDecision($actor, $board)->authorize();
            } elseif (str_ends_with($action, '\\TaskBoardsController@restore')) {
                $this->access->manageArchivedDecision($actor, $board, $scope)->authorize();
            } else {
                $this->access->viewDecision($actor, $board, $scope)->authorize();
            }
        } elseif ($dependency instanceof TaskDependency) {
            // {dependency} carries no board/task ancestor in the route (task-4
            // review, fix round 1), so a bare viewDecision()->authorize() denial
            // here would answer with a generic 'Not Found' — a DIFFERENT body
            // than the 'No query results for model [...] <id>' message route
            // binding already produces for an id that never existed at all,
            // which discloses exactly the thing this check exists to hide.
            // Re-resolving through the same visible-boards query the model
            // itself would be found by makes an invisible dependency raise the
            // identical ModelNotFoundException — same class, same id — as one
            // that was never real, rather than falling through to the generic
            // directoryDecision() branch below, which would 403 instead.
            $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $actor, $scope)
                ->select('task_boards.id');
            $visible = TaskDependency::query()->whereIn('board_id', $visibleBoards)->findOrFail($dependency->id);
            $board = TaskBoard::query()->find($visible->board_id);
        } elseif ($folder instanceof TaskFolder) {
            abort_unless($this->folders->canManage($actor, $folder, $scope), Response::HTTP_NOT_FOUND);
        } else {
            $this->access->directoryDecision($actor, $scope)->authorize();
        }

        $this->assertNestedResources($route, $board, $task);

        return $next($request);
    }

    private function assertNestedResources(object $route, mixed $board, mixed $task): void
    {
        if ($board instanceof TaskBoard) {
            foreach ([
                'group' => TaskGroup::class,
                'status' => TaskStatusOption::class,
                'priority' => TaskPriorityOption::class,
                'column' => TaskColumnDefinition::class,
                'membership' => TaskBoardMember::class,
                'option' => TaskColumnOption::class,
            ] as $parameter => $modelClass) {
                $model = $route->parameter($parameter);
                if ($model instanceof $modelClass) {
                    abort_unless($model->board_id === $board->id, Response::HTTP_NOT_FOUND);
                }
            }

            $column = $route->parameter('column');
            $option = $route->parameter('option');
            if ($column instanceof TaskColumnDefinition && $option instanceof TaskColumnOption) {
                abort_unless($option->column_definition_id === $column->id, Response::HTTP_NOT_FOUND);
            }
        }

        if ($task instanceof Task) {
            foreach ([
                'update' => TaskUpdate::class,
                'link' => TaskRecordLink::class,
            ] as $parameter => $modelClass) {
                $model = $route->parameter($parameter);
                if ($model instanceof $modelClass) {
                    abort_unless($model->task_id === $task->id, Response::HTTP_NOT_FOUND);
                }
            }
        }
    }
}
