<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\BoardVisibility;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskBoardWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class TaskBoardsController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskBoardWriter $writer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaskBoard::class);
        $input = $request->validate([
            'archived' => ['sometimes', 'boolean'],
            'recoverable' => ['sometimes', 'boolean'],
        ]);
        $scope = ViewScope::forUser($request->user());
        if ((bool) ($input['recoverable'] ?? false)) {
            $boards = $this->access->recoverableAtCurrentView($request->user(), $scope);

            return response()->json([
                'data' => $boards->map(fn (TaskBoard $board) => [
                    'id' => $board->id,
                    'company_id' => $board->company_id,
                    'franchise_id' => $board->franchise_id,
                    'actions' => ['recover_owner'],
                ]),
                'meta' => ['total' => $boards->count(), 'capabilities' => [
                    'actions' => $this->access->directoryActions($request->user(), $scope),
                ]],
            ]);
        }
        $archived = (bool) ($input['archived'] ?? false);
        $query = $this->access->constrainVisible(
            TaskBoard::query()->when($archived,
                fn ($query) => $query->archived(),
                fn ($query) => $query->active()),
            $request->user(),
            $scope,
        )->with([
            'groups' => fn ($q) => $q->active()->orderBy('position'),
            'preferences' => fn ($q) => $q->where('user_id', $request->user()->id),
        ]);
        $boards = $query->orderBy('position')->get();

        return response()->json([
            'data' => $boards->map(fn (TaskBoard $board) => $this->present(
                $board, $request->user(), $scope,
            )),
            'meta' => ['total' => $boards->count(), 'capabilities' => [
                'actions' => $this->access->directoryActions($request->user(), $scope),
            ]],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'visibility' => ['required', Rule::in(BoardVisibility::values())],
            'folder_id' => ['nullable', 'uuid'],
            'organization_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'franchise_id' => ['prohibited'],
        ]);
        $board = $this->writer->create($request->user(), ViewScope::forUser($request->user()), $input);

        return response()->json(['data' => $this->present(
            $board, $request->user(), ViewScope::forUser($request->user()),
        )], Response::HTTP_CREATED);
    }

    public function show(Request $request, TaskBoard $board): JsonResponse
    {
        $this->authorize('view', $board);
        $input = $request->validate([
            'include_archived_structure' => ['sometimes', 'boolean'],
        ]);
        $includeArchived = (bool) ($input['include_archived_structure'] ?? false);

        $scope = ViewScope::forUser($request->user());

        return response()->json(['data' => $this->present(
            $board->load($this->relations($request->user()->id, $includeArchived)),
            $request->user(), $scope,
        )]);
    }

    public function update(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string'],
            'visibility' => ['sometimes', Rule::in(BoardVisibility::values())],
            'folder_id' => ['sometimes', 'nullable', 'uuid'],
            'organization_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'franchise_id' => ['prohibited'],
        ]);

        return $this->structureMutation(
            $board,
            fn () => $this->writer->updateBoard(
                $request->user(), $board, ViewScope::forUser($request->user()),
                (int) $input['expected_structure_version'], $input,
            ),
            fn (TaskBoard $result) => $this->present(
                $result, $request->user(), ViewScope::forUser($request->user()),
            ),
        );
    }

    public function archive(Request $request, TaskBoard $board): JsonResponse
    {
        return $this->archiveState($request, $board, true);
    }

    public function restore(Request $request, TaskBoard $board): JsonResponse
    {
        return $this->archiveState($request, $board, false);
    }

    public function duplicate(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'include_tasks' => ['sometimes', 'boolean'],
            'organization_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'franchise_id' => ['prohibited'],
        ]);

        return $this->structureMutation(
            $board,
            fn () => $this->writer->duplicate(
                $request->user(), $board, ViewScope::forUser($request->user()),
                (int) $input['expected_structure_version'], $input['name'],
                (bool) ($input['include_tasks'] ?? false),
            ),
            fn (TaskBoard $result) => $this->present(
                $result, $request->user(), ViewScope::forUser($request->user()),
            ),
            Response::HTTP_CREATED,
        );
    }

    private function archiveState(Request $request, TaskBoard $board, bool $archived): JsonResponse
    {
        $input = $request->validate(['expected_structure_version' => ['required', 'integer', 'min:1']]);

        return $this->structureMutation(
            $board,
            fn () => $this->writer->setArchived(
                $request->user(), $board, ViewScope::forUser($request->user()),
                (int) $input['expected_structure_version'], $archived,
            ),
            fn (TaskBoard $result) => $this->present(
                $result, $request->user(), ViewScope::forUser($request->user()),
            ),
        );
    }

    private function present(TaskBoard $board, $actor, ViewScope $scope): array
    {
        return [
            'id' => $board->id,
            'name' => $board->name,
            'description' => $board->description,
            'visibility' => $board->visibility->value,
            'company_id' => $board->company_id,
            'franchise_id' => $board->franchise_id,
            'folder_id' => $board->folder_id,
            'structure_version' => (int) $board->structure_version,
            'archived' => $board->archived_at !== null,
            'is_favorite' => (bool) ($board->relationLoaded('preferences')
                ? $board->preferences->first()?->is_favorite
                : false),
            'last_opened_at' => $board->relationLoaded('preferences')
                ? $board->preferences->first()?->last_opened_at?->toIso8601String()
                : null,
            'actions' => $this->access->boardActions($actor, $board, $scope),
            'groups' => $board->relationLoaded('groups')
                ? $board->groups->sortBy([['position', 'asc'], ['id', 'asc']])->map(fn ($group) => [
                    'id' => $group->id, 'name' => $group->name, 'color' => $group->color,
                    'position' => (int) $group->position,
                    'order_version' => (int) $group->order_version,
                    'archived' => $group->archived_at !== null,
                    'actions' => $this->structureActions($actor, $board, $scope, $group->archived_at !== null),
                ])->values()
                : [],
            'statuses' => $board->relationLoaded('statusOptions')
                ? $board->statusOptions->sortBy([['position', 'asc'], ['id', 'asc']])->map(fn ($status) => [
                    'id' => $status->id, 'name' => $status->name, 'color' => $status->color,
                    'category' => $status->category->value, 'archived' => $status->archived_at !== null,
                    'position' => (int) $status->position,
                    'actions' => $this->structureActions($actor, $board, $scope, $status->archived_at !== null),
                ])->values()
                : [],
            'priorities' => $board->relationLoaded('priorityOptions')
                ? $board->priorityOptions->sortBy([['position', 'asc'], ['id', 'asc']])->map(fn ($priority) => [
                    'id' => $priority->id, 'name' => $priority->name, 'color' => $priority->color,
                    'rank' => $priority->rank->value, 'archived' => $priority->archived_at !== null,
                    'position' => (int) $priority->position,
                    'actions' => $this->structureActions($actor, $board, $scope, $priority->archived_at !== null),
                ])->values()
                : [],
            'core_columns' => $board->relationLoaded('coreColumnSettings')
                ? $board->coreColumnSettings->sortBy('core_key')->map(fn ($column) => [
                    'core_key' => $column->core_key, 'display_label' => $column->display_label,
                    'actions' => $this->access->boardActions($actor, $board, $scope) !== []
                        && in_array('manage_structure', $this->access->boardActions($actor, $board, $scope), true)
                            ? ['update'] : [],
                ])->values()
                : [],
            'custom_columns' => $board->relationLoaded('columnDefinitions')
                ? $board->columnDefinitions->sortBy([['position', 'asc'], ['id', 'asc']])->map(fn ($column) => [
                    'id' => $column->id, 'name' => $column->name, 'type' => $column->type->value,
                    'number_suffix' => $column->number_suffix, 'archived' => $column->archived_at !== null,
                    'position' => (int) $column->position,
                    'actions' => $this->structureActions($actor, $board, $scope, $column->archived_at !== null),
                    'options' => $column->options->sortBy([['position', 'asc'], ['id', 'asc']])->map(fn ($option) => [
                        'id' => $option->id, 'name' => $option->name, 'color' => $option->color,
                        'archived' => $option->archived_at !== null,
                        'position' => (int) $option->position,
                        'actions' => $this->structureActions($actor, $board, $scope, $option->archived_at !== null),
                    ])->values(),
                ])->values()
                : [],
            'views' => $board->relationLoaded('views')
                ? $board->views->map(fn ($view) => [
                    'id' => $view->id, 'name' => $view->name,
                    'visibility' => $view->visibility, 'locked' => $view->is_locked,
                    'permanent_main' => $view->is_permanent_main,
                ])->values()
                : [],
        ];
    }

    private function relations(string $userId, bool $includeArchived = false): array
    {
        return [
            'groups' => fn ($query) => $query
                ->when(! $includeArchived, fn ($query) => $query->active())
                ->orderBy('position')->orderBy('id'),
            'statusOptions' => fn ($query) => $query
                ->when(! $includeArchived, fn ($query) => $query->active())
                ->orderBy('position')->orderBy('id'),
            'priorityOptions' => fn ($query) => $query
                ->when(! $includeArchived, fn ($query) => $query->active())
                ->orderBy('position')->orderBy('id'),
            'coreColumnSettings' => fn ($query) => $query->orderBy('core_key'),
            'columnDefinitions' => fn ($query) => $query
                ->when(! $includeArchived, fn ($query) => $query->active())
                ->orderBy('position')->orderBy('id'),
            'columnDefinitions.options' => fn ($query) => $query
                ->when(! $includeArchived, fn ($query) => $query->active())
                ->orderBy('position')->orderBy('id'),
            'views' => fn ($query) => $query->where(fn ($visible) => $visible
                ->where('visibility', 'shared')
                ->orWhere('owner_user_id', $userId)),
            'members',
            'preferences' => fn ($query) => $query->where('user_id', $userId),
        ];
    }

    /** @return list<string> */
    private function structureActions($actor, TaskBoard $board, ViewScope $scope, bool $archived): array
    {
        if (! in_array('manage_structure', $this->access->boardActions($actor, $board, $scope), true)) {
            return [];
        }

        return $archived ? ['restore'] : ['update', 'archive'];
    }
}
