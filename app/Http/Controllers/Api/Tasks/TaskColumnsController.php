<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnOption;
use App\Models\TaskCoreColumnSetting;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\ColumnType;
use App\Support\Tasks\TaskBoardWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class TaskColumnsController extends TaskApiController
{
    public function __construct(private readonly TaskBoardWriter $writer) {}

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $this->validated($request, true);

        return $this->mutate($board, fn () => $this->writer->createColumn(
            $request->user(), $board, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ), Response::HTTP_CREATED);
    }

    public function update(Request $request, TaskBoard $board, TaskColumnDefinition $column): JsonResponse
    {
        $input = $this->validated($request, false);

        return $this->mutate($board, fn () => $this->writer->updateColumn(
            $request->user(), $board, $column, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ));
    }

    public function archive(Request $request, TaskBoard $board, TaskColumnDefinition $column): JsonResponse
    {
        return $this->archiveState($request, $board, $column, true);
    }

    public function restore(Request $request, TaskBoard $board, TaskColumnDefinition $column): JsonResponse
    {
        return $this->archiveState($request, $board, $column, false);
    }

    public function reorder(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['uuid'],
        ]);

        return $this->structureMutation($board, fn () => $this->writer->reorderColumns(
            $request->user(), $board, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input['ordered_ids'],
        ), fn () => ['ordered_ids' => $input['ordered_ids']]);
    }

    public function updateCore(Request $request, TaskBoard $board, string $coreKey): JsonResponse
    {
        $input = $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'display_label' => ['required', 'string', 'max:191'],
        ]);

        return $this->structureMutation($board, fn () => $this->writer->updateCoreLabel(
            $request->user(), $board, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $coreKey, $input['display_label'],
        ), fn (TaskCoreColumnSetting $setting) => [
            'core_key' => $setting->core_key,
            'display_label' => $setting->display_label,
        ]);
    }

    public function storeOption(Request $request, TaskBoard $board, TaskColumnDefinition $column): JsonResponse
    {
        $input = $this->optionInput($request, true);

        return $this->optionMutation($board, fn () => $this->writer->createColumnOption(
            $request->user(), $board, $column, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ), Response::HTTP_CREATED);
    }

    public function updateOption(Request $request, TaskBoard $board, TaskColumnDefinition $column, TaskColumnOption $option): JsonResponse
    {
        $input = $this->optionInput($request, false);

        return $this->optionMutation($board, fn () => $this->writer->updateColumnOption(
            $request->user(), $board, $column, $option, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input,
        ));
    }

    public function archiveOption(Request $request, TaskBoard $board, TaskColumnDefinition $column, TaskColumnOption $option): JsonResponse
    {
        return $this->optionArchiveState($request, $board, $column, $option, true);
    }

    public function restoreOption(Request $request, TaskBoard $board, TaskColumnDefinition $column, TaskColumnOption $option): JsonResponse
    {
        return $this->optionArchiveState($request, $board, $column, $option, false);
    }

    public function reorderOptions(Request $request, TaskBoard $board, TaskColumnDefinition $column): JsonResponse
    {
        $input = $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['uuid'],
        ]);

        return $this->structureMutation($board, fn () => $this->writer->reorderColumnOptions(
            $request->user(), $board, $column, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $input['ordered_ids'],
        ), fn () => ['ordered_ids' => $input['ordered_ids']]);
    }

    private function archiveState(Request $request, TaskBoard $board, TaskColumnDefinition $column, bool $archived): JsonResponse
    {
        $input = $request->validate(['expected_structure_version' => ['required', 'integer', 'min:1']]);

        return $this->mutate($board, fn () => $this->writer->setColumnArchived(
            $request->user(), $board, $column, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $archived,
        ));
    }

    private function mutate(TaskBoard $board, callable $callback, int $status = 200): JsonResponse
    {
        return $this->structureMutation($board, $callback, fn (TaskColumnDefinition $column) => [
            'id' => $column->id,
            'name' => $column->name,
            'type' => $column->type->value,
            'number_suffix' => $column->number_suffix,
            'position' => (int) $column->position,
            'archived' => $column->archived_at !== null,
        ], $status);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(ColumnType::values())],
            'number_suffix' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
    }

    private function optionArchiveState(Request $request, TaskBoard $board, TaskColumnDefinition $column, TaskColumnOption $option, bool $archived): JsonResponse
    {
        $input = $request->validate(['expected_structure_version' => ['required', 'integer', 'min:1']]);

        return $this->optionMutation($board, fn () => $this->writer->setColumnOptionArchived(
            $request->user(), $board, $column, $option, ViewScope::forUser($request->user()),
            (int) $input['expected_structure_version'], $archived,
        ));
    }

    private function optionMutation(TaskBoard $board, callable $callback, int $status = 200): JsonResponse
    {
        return $this->structureMutation($board, $callback, fn (TaskColumnOption $option) => [
            'id' => $option->id,
            'name' => $option->name,
            'color' => $option->color,
            'position' => (int) $option->position,
            'archived' => $option->archived_at !== null,
        ], $status);
    }

    private function optionInput(Request $request, bool $creating): array
    {
        return $request->validate([
            'expected_structure_version' => ['required', 'integer', 'min:1'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
    }
}
