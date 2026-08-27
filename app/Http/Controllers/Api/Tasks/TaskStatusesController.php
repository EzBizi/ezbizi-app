<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskStatusOption;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskBoardWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class TaskStatusesController extends TaskApiController
{
    public function __construct(private readonly TaskBoardWriter $writer) {}

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $this->validated($request, true);

        return $this->mutate($board, fn () => $this->writer->createStatus(
            $request->user(), $board, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ), Response::HTTP_CREATED);
    }

    public function update(Request $request, TaskBoard $board, TaskStatusOption $status): JsonResponse
    {
        $input = $this->validated($request, false);

        return $this->mutate($board, fn () => $this->writer->updateStatus(
            $request->user(), $board, $status, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ));
    }

    public function archive(Request $request, TaskBoard $board, TaskStatusOption $status): JsonResponse
    {
        return $this->archiveState($request, $board, $status, true);
    }

    public function restore(Request $request, TaskBoard $board, TaskStatusOption $status): JsonResponse
    {
        return $this->archiveState($request, $board, $status, false);
    }

    public function reorder(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $this->reorderInput($request);

        return $this->structureMutation($board, fn () => $this->writer->reorderStatuses(
            $request->user(), $board, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input['ordered_ids'],
        ), fn () => ['ordered_ids' => $input['ordered_ids']]);
    }

    private function archiveState(Request $request, TaskBoard $board, TaskStatusOption $status, bool $archived): JsonResponse
    {
        $input = $request->validate(['expected_structure_version' => ['required', 'integer', 'min:1']]);

        return $this->mutate($board, fn () => $this->writer->setStatusArchived(
            $request->user(), $board, $status, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $archived,
        ));
    }

    private function mutate(TaskBoard $board, callable $callback, int $status = 200): JsonResponse
    {
        return $this->structureMutation($board, $callback, fn (TaskStatusOption $option) => [
            'id' => $option->id,
            'name' => $option->name,
            'color' => $option->color,
            'category' => $option->category->value,
            'position' => (int) $option->position,
            'archived' => $option->archived_at !== null,
        ], $status);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'category' => [$creating ? 'required' : 'sometimes', Rule::in(StatusCategory::values())],
        ]);
    }

    private function reorderInput(Request $request): array
    {
        return $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['uuid'],
        ]);
    }
}
