<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TasksController extends TaskApiController
{
    public function __construct(private readonly TaskWriter $writer) {}

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $this->validated($request, creating: true);

        return $this->taskMutation(fn () => $this->writer->create(
            $request->user(),
            $board,
            ViewScope::forUser($request->user()),
            $input,
        ), Response::HTTP_CREATED);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $input = $this->validated($request);
        $expectedVersion = (int) $input['expected_version'];
        unset($input['expected_version']);

        return $this->taskMutation(fn () => $this->writer->update(
            $request->user(),
            $task,
            ViewScope::forUser($request->user()),
            $expectedVersion,
            $input,
        ));
    }

    public function archive(Request $request, Task $task): JsonResponse
    {
        return $this->archiveState($request, $task, true);
    }

    public function restore(Request $request, Task $task): JsonResponse
    {
        return $this->archiveState($request, $task, false);
    }

    private function archiveState(Request $request, Task $task, bool $archived): JsonResponse
    {
        $input = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return $this->taskMutation(fn () => $this->writer->setArchived(
            $request->user(),
            $task,
            ViewScope::forUser($request->user()),
            (int) $input['expected_version'],
            $archived,
        ));
    }

    private function validated(Request $request, bool $creating = false): array
    {
        $rules = [
            'expected_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string'],
            'group_id' => ['sometimes', 'nullable', 'uuid'],
            'parent_task_id' => ['sometimes', 'nullable', 'uuid'],
            'owner_user_id' => ['sometimes', 'nullable', 'uuid'],
            'collaborator_user_ids' => ['sometimes', 'array', 'max:100'],
            'collaborator_user_ids.*' => ['uuid', 'distinct'],
            'status_option_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'priority_option_id' => ['sometimes', 'nullable', 'uuid'],
            'start_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'organization_id' => ['prohibited'],
            'board_id' => ['prohibited'],
            'position' => ['prohibited'],
            'version' => ['prohibited'],
        ];

        $input = $request->validate($rules);
        if (! $creating && count($input) === 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Submit at least one task field to change.');
        }

        return $input;
    }
}
