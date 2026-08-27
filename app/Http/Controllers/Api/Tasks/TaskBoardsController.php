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
        $query = $this->access->constrainVisible(
            TaskBoard::query()->active(),
            $request->user(),
            ViewScope::forUser($request->user()),
        )->with(['groups' => fn ($q) => $q->active()->orderBy('position')]);
        $boards = $query->orderBy('position')->get();

        return response()->json([
            'data' => $boards->map(fn (TaskBoard $board) => $this->present($board)),
            'meta' => ['total' => $boards->count()],
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

        return response()->json(['data' => $this->present($board)], Response::HTTP_CREATED);
    }

    public function show(Request $request, TaskBoard $board): JsonResponse
    {
        $this->authorize('view', $board);

        return response()->json(['data' => $this->present($board->load($this->relations()))]);
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
            fn (TaskBoard $result) => $this->present($result),
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
            fn (TaskBoard $result) => $this->present($result),
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
            fn (TaskBoard $result) => $this->present($result),
        );
    }

    private function present(TaskBoard $board): array
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
            'groups' => $board->relationLoaded('groups')
                ? $board->groups->map(fn ($group) => ['id' => $group->id, 'name' => $group->name, 'color' => $group->color])->values()
                : [],
            'statuses' => $board->relationLoaded('statusOptions')
                ? $board->statusOptions->map(fn ($status) => [
                    'id' => $status->id, 'name' => $status->name, 'color' => $status->color,
                    'category' => $status->category->value, 'archived' => $status->archived_at !== null,
                ])->values()
                : [],
            'priorities' => $board->relationLoaded('priorityOptions')
                ? $board->priorityOptions->map(fn ($priority) => [
                    'id' => $priority->id, 'name' => $priority->name, 'color' => $priority->color,
                    'rank' => $priority->rank->value, 'archived' => $priority->archived_at !== null,
                ])->values()
                : [],
            'core_columns' => $board->relationLoaded('coreColumnSettings')
                ? $board->coreColumnSettings->map(fn ($column) => [
                    'core_key' => $column->core_key, 'display_label' => $column->display_label,
                ])->values()
                : [],
            'custom_columns' => $board->relationLoaded('columnDefinitions')
                ? $board->columnDefinitions->map(fn ($column) => [
                    'id' => $column->id, 'name' => $column->name, 'type' => $column->type->value,
                    'number_suffix' => $column->number_suffix, 'archived' => $column->archived_at !== null,
                    'options' => $column->options->map(fn ($option) => [
                        'id' => $option->id, 'name' => $option->name, 'color' => $option->color,
                        'archived' => $option->archived_at !== null,
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

    private function relations(): array
    {
        return [
            'groups', 'statusOptions', 'priorityOptions', 'coreColumnSettings',
            'columnDefinitions.options', 'views', 'members',
        ];
    }
}
