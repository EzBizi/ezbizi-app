<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskGroupsController extends TaskApiController
{
    public function __construct(private readonly TaskBoardWriter $writer) {}

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $this->validated($request, creating: true);

        return $this->mutate($request, $board, fn () => $this->writer->createGroup(
            $request->user(), $board, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ), Response::HTTP_CREATED);
    }

    public function update(Request $request, TaskBoard $board, TaskGroup $group): JsonResponse
    {
        $input = $this->validated($request);

        return $this->mutate($request, $board, fn () => $this->writer->updateGroup(
            $request->user(), $board, $group, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ));
    }

    public function archive(Request $request, TaskBoard $board, TaskGroup $group): JsonResponse
    {
        return $this->archiveState($request, $board, $group, true);
    }

    public function restore(Request $request, TaskBoard $board, TaskGroup $group): JsonResponse
    {
        return $this->archiveState($request, $board, $group, false);
    }

    public function reorder(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['uuid'],
        ]);

        return $this->structureMutation(
            $board,
            fn () => $this->writer->reorderGroups(
                $request->user(), $board, ViewScope::forUser($request->user()),
                (int) $input['expected_structure_version'], $input['ordered_ids'],
            ),
            fn () => ['ordered_ids' => $input['ordered_ids']],
        );
    }

    private function archiveState(Request $request, TaskBoard $board, TaskGroup $group, bool $archived): JsonResponse
    {
        $input = $request->validate(['expected_structure_version' => ['required', 'integer', 'min:1']]);

        return $this->mutate($request, $board, fn () => $this->writer->setGroupArchived(
            $request->user(), $board, $group, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $archived,
        ));
    }

    private function mutate(Request $request, TaskBoard $board, callable $callback, int $status = 200): JsonResponse
    {
        return $this->structureMutation($board, $callback, fn (TaskGroup $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'color' => $group->color,
            'position' => (int) $group->position,
            'archived' => $group->archived_at !== null,
        ], $status);
    }

    private function validated(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
    }
}
