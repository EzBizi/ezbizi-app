<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskView;
use App\Models\TaskViewPreference;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskStructureConflict;
use App\Support\Tasks\TaskViewConflict;
use App\Support\Tasks\TaskViewWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class TaskViewsController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskViewWriter $writer,
    ) {}

    public function index(Request $request, TaskBoard $board): JsonResponse
    {
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $request->user(), $scope)
            ->select('task_boards.id');
        $views = TaskView::query()->where('board_id', $board->id)->whereIn('board_id', $visibleBoards)
            ->where(fn ($visible) => $visible->where('visibility', 'shared')
                ->orWhere('owner_user_id', $request->user()->id))
            ->with(['preferences' => fn ($query) => $query->where('user_id', $request->user()->id)])
            ->orderBy('visibility')->orderBy('position')->get();

        return response()->json([
            'data' => $views->map(fn (TaskView $view) => $this->present($view, $request->user(), $board, $scope))->values(),
            'meta' => ['capabilities' => $this->creationCapabilities($request->user(), $board, $scope)],
        ]);
    }

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'visibility' => ['required', Rule::in(['personal', 'shared'])],
            'is_locked' => ['sometimes', 'boolean'],
            'configuration' => ['required', 'array'],
            'expected_structure_version' => ['sometimes', 'integer', 'min:1'],
        ]);

        $scope = ViewScope::forUser($request->user());
        try {
            $result = $this->writer->create($request->user(), $board, $scope, $input);
        } catch (TaskStructureConflict $conflict) {
            return $this->structureConflict($conflict);
        }

        return response()->json([
            'data' => $this->present($result['view'], $request->user(), $board, $scope),
            'meta' => ['structure_version' => $result['structure_version']],
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, TaskBoard $board, string $viewId): JsonResponse
    {
        $input = $request->validate([
            'expected_view_version' => ['required', 'integer', 'min:1'],
            'expected_structure_version' => ['sometimes', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:191'],
            'visibility' => ['sometimes', Rule::in(['personal', 'shared'])],
            'is_locked' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'array'],
        ]);

        $scope = ViewScope::forUser($request->user());
        try {
            $result = $this->writer->update($request->user(), $board, $scope, $viewId, $input);
        } catch (TaskViewConflict $conflict) {
            return $this->viewConflict($conflict, $request->user(), $board, $scope);
        } catch (TaskStructureConflict $conflict) {
            return $this->structureConflict($conflict);
        }

        return response()->json([
            'data' => $this->present($result['view'], $request->user(), $board, $scope),
            'meta' => ['structure_version' => $result['structure_version']],
        ]);
    }

    public function destroy(Request $request, TaskBoard $board, string $viewId): Response
    {
        $input = $request->validate([
            'expected_view_version' => ['sometimes', 'integer', 'min:1'],
            'expected_structure_version' => ['sometimes', 'integer', 'min:1'],
        ]);
        $scope = ViewScope::forUser($request->user());
        try {
            $this->writer->delete($request->user(), $board, $scope, $viewId, $input);
        } catch (TaskViewConflict $conflict) {
            return $this->viewConflict($conflict, $request->user(), $board, $scope);
        } catch (TaskStructureConflict $conflict) {
            return $this->structureConflict($conflict);
        }

        return response('', Response::HTTP_NO_CONTENT);
    }

    public function duplicate(Request $request, TaskBoard $board, string $viewId): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'expected_view_version' => ['required', 'integer', 'min:1'],
        ]);
        $scope = ViewScope::forUser($request->user());
        try {
            $copy = $this->writer->duplicate(
                $request->user(), $board, $scope, $viewId,
                $input['name'], (int) $input['expected_view_version'],
            );
        } catch (TaskViewConflict $conflict) {
            return $this->viewConflict($conflict, $request->user(), $board, $scope);
        }

        return response()->json([
            'data' => $this->present($copy, $request->user(), $board, $scope),
        ], Response::HTTP_CREATED);
    }

    public function reorder(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'visibility' => ['required', Rule::in(['personal', 'shared'])],
            'view_ids' => ['required', 'array', 'min:1', 'max:100'],
            'view_ids.*' => ['uuid', 'distinct'],
            'expected_structure_version' => ['required_if:visibility,shared', 'integer', 'min:1'],
            'expected_view_versions' => ['required_if:visibility,personal', 'array'],
            'expected_view_versions.*' => ['integer', 'min:1'],
        ]);
        $scope = ViewScope::forUser($request->user());
        try {
            $result = $this->writer->reorder($request->user(), $board, $scope, $input);
        } catch (TaskViewConflict $conflict) {
            return $this->viewConflict($conflict, $request->user(), $board, $scope);
        } catch (TaskStructureConflict $conflict) {
            return $this->structureConflict($conflict);
        }

        return response()->json([
            'data' => collect($result['views'])->map(
                fn (TaskView $view) => $this->present($view, $request->user(), $board, $scope)
            )->values(),
            'meta' => ['structure_version' => $result['structure_version']],
        ]);
    }

    public function preference(Request $request, TaskBoard $board, string $viewId): JsonResponse
    {
        $input = $request->validate([
            'collapsed_group_ids' => ['required', 'array', 'max:100'],
            'collapsed_group_ids.*' => ['uuid', 'distinct'],
            'density' => ['required', Rule::in(['compact', 'comfortable'])],
            'is_last_active' => ['required', 'boolean'],
            'scroll_left' => ['required', 'integer', 'min:0'],
            'scroll_top' => ['required', 'integer', 'min:0'],
        ]);
        $preference = $this->writer->preference(
            $request->user(), $board, ViewScope::forUser($request->user()), $viewId, $input,
        );

        return response()->json(['data' => $this->presentPreference($preference)]);
    }

    private function viewConflict(
        TaskViewConflict $conflict,
        User $actor,
        TaskBoard $board,
        ViewScope $scope,
    ): JsonResponse
    {
        return response()->json([
            'message' => $conflict->getMessage(),
            'data' => $this->present($conflict->view, $actor, $board, $scope),
            'meta' => ['current_view_version' => (int) $conflict->view->version],
        ], Response::HTTP_CONFLICT);
    }

    private function structureConflict(TaskStructureConflict $conflict): JsonResponse
    {
        return response()->json([
            'message' => $conflict->getMessage(),
            'meta' => ['current_structure_version' => $conflict->currentVersion],
        ], Response::HTTP_CONFLICT);
    }

    private function present(TaskView $view, User $actor, TaskBoard $board, ViewScope $scope): array
    {
        $configuration = $view->configuration;
        $configuration['column_widths'] = (object) ($configuration['column_widths'] ?? []);

        return [
            'id' => $view->id,
            'board_id' => $view->board_id,
            'owner_user_id' => $view->owner_user_id,
            'visibility' => $view->visibility,
            'name' => $view->name,
            'is_locked' => (bool) $view->is_locked,
            'is_permanent_main' => (bool) $view->is_permanent_main,
            'configuration' => $configuration,
            'version' => (int) $view->version,
            'position' => (int) $view->position,
            'actions' => $this->viewActions($view, $actor, $board, $scope),
            'preference' => $view->relationLoaded('preferences') && $view->preferences->first()
                ? $this->presentPreference($view->preferences->first()) : null,
        ];
    }

    /** @return array{create_personal:bool,create_shared:bool} */
    private function creationCapabilities(User $actor, TaskBoard $board, ViewScope $scope): array
    {
        $writable = $this->writable($actor, $board, $scope);

        return [
            'create_personal' => $writable,
            'create_shared' => $writable && $this->access->manageDecision($actor, $board, $scope)->allowed(),
        ];
    }

    /** @return list<string> */
    private function viewActions(TaskView $view, User $actor, TaskBoard $board, ViewScope $scope): array
    {
        $writable = $this->writable($actor, $board, $scope);
        $manage = $writable && $this->access->manageDecision($actor, $board, $scope)->allowed();
        $personal = $view->visibility === 'personal' && $view->owner_user_id === $actor->id;
        $update = $personal
            ? $writable
            : ($view->is_locked ? $manage : $writable && $actor->hasPermission('tasks.edit'));

        return array_values(array_filter([
            $update ? 'update' : null,
            $view->visibility === 'shared' && $writable ? 'duplicate' : null,
            ! $view->is_permanent_main && ($personal ? $writable : $manage) ? 'delete' : null,
        ]));
    }

    private function writable(User $actor, TaskBoard $board, ViewScope $scope): bool
    {
        return $this->access->viewDecision($actor, $board, $scope)->allowed()
            && ! $actor->organization?->isReadOnly();
    }

    private function presentPreference(TaskViewPreference $preference): array
    {
        return [
            'view_id' => $preference->view_id,
            'collapsed_group_ids' => $preference->collapsed_group_ids,
            'density' => $preference->density,
            'is_last_active' => (bool) $preference->is_last_active,
            'scroll_left' => (int) $preference->scroll_left,
            'scroll_top' => (int) $preference->scroll_top,
        ];
    }
}
