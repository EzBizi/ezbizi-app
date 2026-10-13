<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Support\Audit\AuditLogger;
use App\Support\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A board's own statuses, custom columns and groups. Every write needs
 * TaskBoards::canEdit (TaskBoardPolicy::update), like adding a task.
 */
class TaskBoardSettingsController extends Controller
{
    public function __construct(
        private readonly TaskWriter $writer,
        private readonly AuditLogger $audit,
    ) {}

    // ── Statuses ─────────────────────────────────────────────────────────

    public function storeStatus(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('update', $taskBoard);
        $input = $request->validate($this->statusRules(required: true));
        $this->uniqueStatusName($taskBoard, $input['name']);

        $status = TaskStatus::query()->create([
            'organization_id' => $taskBoard->organization_id,
            'board_id' => $taskBoard->id,
            'name' => $input['name'],
            'colour' => $input['colour'] ?? null,
            'is_done' => $input['is_done'] ?? false,
            'position' => $input['position'] ?? (int) $taskBoard->statuses()->max('position') + 1,
        ]);
        $this->audit->log('task_board.status_created', $status, actor: $request->user());

        return response()->json(['data' => self::presentStatus($status)], 201);
    }

    public function updateStatus(Request $request, TaskBoard $taskBoard, string $status): JsonResponse
    {
        $this->authorize('update', $taskBoard);
        $row = $this->statusOn($taskBoard, $status);
        $input = $request->validate($this->statusRules(required: false));
        if (isset($input['name'])) {
            $this->uniqueStatusName($taskBoard, $input['name'], except: $row->id);
        }

        DB::transaction(function () use ($row, $input, $request) {
            $row->fill($input);
            $doneChanged = $row->isDirty('is_done');
            $this->audit->logChange('task_board.status_updated', $row, $request->user());
            $row->save();
            if ($doneChanged) {
                $this->writer->rederive($row);
            }
        });

        return response()->json(['data' => self::presentStatus($row)]);
    }

    public function destroyStatus(Request $request, TaskBoard $taskBoard, string $status): Response
    {
        $this->authorize('update', $taskBoard);
        $row = $this->statusOn($taskBoard, $status);
        $moveTo = $request->input('move_to');

        if ($taskBoard->statuses()->count() <= 1) {
            throw ValidationException::withMessages(['status' => 'A board needs at least one status.']);
        }

        $inUse = Task::query()->withTrashed()->where('status_id', $row->id)->exists();
        if ($inUse && ! $moveTo) {
            throw ValidationException::withMessages(['move_to' => 'Tasks use this status: choose a status to move them to.']);
        }

        DB::transaction(function () use ($taskBoard, $row, $moveTo, $inUse, $request) {
            if ($inUse) {
                $to = $this->statusOn($taskBoard, (string) $moveTo, field: 'move_to');
                if ($to->id === $row->id) {
                    throw ValidationException::withMessages(['move_to' => 'Choose a different status.']);
                }
                $this->writer->moveStatus($row, $to, $request->user());
            }
            $this->audit->log('task_board.status_deleted', $row, actor: $request->user());
            $row->delete();
        });

        return response()->noContent();
    }

    // ── Columns ──────────────────────────────────────────────────────────

    public function storeColumn(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('update', $taskBoard);
        $input = $request->validate($this->columnRules(required: true));

        $column = TaskColumn::query()->create([
            'organization_id' => $taskBoard->organization_id,
            'board_id' => $taskBoard->id,
            'name' => $input['name'],
            'type' => $input['type'],
            'settings' => $this->settings($input['type'], $input['settings'] ?? null),
            'hidden' => $input['hidden'] ?? false,
            'position' => $input['position'] ?? (int) $taskBoard->columns()->max('position') + 1,
        ]);
        $this->audit->log('task_board.column_created', $column, actor: $request->user());

        return response()->json(['data' => self::presentColumn($column)], 201);
    }

    public function updateColumn(Request $request, TaskBoard $taskBoard, string $column): JsonResponse
    {
        $this->authorize('update', $taskBoard);
        $row = TaskColumn::query()->where('board_id', $taskBoard->id)->findOrFail($column);
        $input = $request->validate($this->columnRules(required: false));
        $type = $input['type'] ?? $row->type;

        if ($type !== $row->type && $row->values()->exists()) {
            throw ValidationException::withMessages(['type' => 'This column has values: its type cannot change.']);
        }

        DB::transaction(function () use ($row, $input, $type, $request) {
            $row->fill(array_intersect_key($input, array_flip(['name', 'position', 'hidden'])));
            $row->type = $type;
            if (array_key_exists('settings', $input) || $type !== $row->getOriginal('type')) {
                $row->settings = $this->settings($type, array_key_exists('settings', $input) ? $input['settings'] : $row->settings);
                // A value pointing at an option that no longer exists is cleared.
                if (in_array($type, TaskColumn::WITH_OPTIONS, true)) {
                    $row->values()->whereNotIn('value_text', array_column($row->options(), 'id'))->delete();
                }
            }
            $this->audit->logChange('task_board.column_updated', $row, $request->user());
            $row->save();
        });

        return response()->json(['data' => self::presentColumn($row)]);
    }

    public function destroyColumn(Request $request, TaskBoard $taskBoard, string $column): Response
    {
        $this->authorize('update', $taskBoard);
        $row = TaskColumn::query()->where('board_id', $taskBoard->id)->findOrFail($column);
        $this->audit->log('task_board.column_deleted', $row, actor: $request->user());
        $row->delete(); // its values cascade

        return response()->noContent();
    }

    // ── Groups ───────────────────────────────────────────────────────────

    public function storeGroup(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('update', $taskBoard);
        $input = $request->validate($this->groupRules(required: true));

        $group = TaskGroup::query()->create([
            'organization_id' => $taskBoard->organization_id,
            'board_id' => $taskBoard->id,
            'name' => $input['name'],
            'colour' => $input['colour'] ?? null,
            'position' => $input['position'] ?? (int) $taskBoard->groups()->max('position') + 1,
        ]);
        $this->audit->log('task_board.group_created', $group, actor: $request->user());

        return response()->json(['data' => self::presentGroup($group)], 201);
    }

    public function updateGroup(Request $request, TaskBoard $taskBoard, string $group): JsonResponse
    {
        $this->authorize('update', $taskBoard);
        $row = TaskGroup::query()->where('board_id', $taskBoard->id)->findOrFail($group);
        $row->fill($request->validate($this->groupRules(required: false)));
        $this->audit->logChange('task_board.group_updated', $row, $request->user());
        $row->save();

        return response()->json(['data' => self::presentGroup($row)]);
    }

    public function destroyGroup(Request $request, TaskBoard $taskBoard, string $group): Response
    {
        $this->authorize('update', $taskBoard);
        $row = TaskGroup::query()->where('board_id', $taskBoard->id)->findOrFail($group);
        $moveTo = $request->input('move_to');

        if ($taskBoard->groups()->count() <= 1) {
            throw ValidationException::withMessages(['group' => 'A board needs at least one group.']);
        }
        if (! $moveTo && Task::query()->where('group_id', $row->id)->exists()) {
            throw ValidationException::withMessages(['move_to' => 'This group has tasks: choose a group to move them to.']);
        }

        // Deleted tasks move too: the group's FK would otherwise hard-delete them.
        $to = $moveTo
            ? TaskGroup::query()->where('board_id', $taskBoard->id)->whereKeyNot($row->id)->find($moveTo)
                ?? throw ValidationException::withMessages(['move_to' => 'That group is not on this board.'])
            : TaskGroup::query()->where('board_id', $taskBoard->id)->whereKeyNot($row->id)->orderBy('position')->first();

        DB::transaction(function () use ($row, $to, $request) {
            Task::query()->withTrashed()->where('group_id', $row->id)->update(['group_id' => $to->id]);
            $this->audit->log('task_board.group_deleted', $row, actor: $request->user());
            $row->delete();
        });

        return response()->noContent();
    }

    // ── Shapes ───────────────────────────────────────────────────────────

    public static function presentStatus(TaskStatus $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'colour' => $s->colour, 'position' => $s->position, 'is_done' => $s->is_done];
    }

    public static function presentColumn(TaskColumn $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'type' => $c->type,
            'settings' => (object) ($c->settings ?? []),
            'hidden' => (bool) $c->getAttribute('hidden'),
            'position' => $c->position,
        ];
    }

    public static function presentGroup(TaskGroup $g): array
    {
        return ['id' => $g->id, 'name' => $g->name, 'colour' => $g->colour, 'position' => $g->position];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function statusOn(TaskBoard $board, string $id, string $field = 'status'): TaskStatus
    {
        $row = TaskStatus::query()->where('board_id', $board->id)->find($id);
        if ($row === null) {
            $field === 'status'
                ? abort(404)
                : throw ValidationException::withMessages([$field => 'That status is not on this board.']);
        }

        return $row;
    }

    /** Names are how automations name a status, so two on one board may not share one. */
    private function uniqueStatusName(TaskBoard $board, string $name, ?string $except = null): void
    {
        $taken = $board->statuses()->get()
            ->contains(fn (TaskStatus $s) => $s->id !== $except && mb_strtolower($s->name) === mb_strtolower($name));
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'This board already has a status with that name.']);
        }
    }

    /**
     * {unit?, options?}. status/dropdown options as [{id, label, colour}],
     * with a stable id generated for any option that has none; other types
     * carry no options.
     */
    private function settings(string $type, ?array $settings): ?array
    {
        $out = [];
        $unit = $settings['unit'] ?? null;
        if ($unit !== null) {
            if (! is_string($unit) || mb_strlen($unit) > 32) {
                throw ValidationException::withMessages(['settings.unit' => 'A unit of up to 32 characters.']);
            }
            $out['unit'] = $unit;
        }
        if (in_array($type, TaskColumn::WITH_OPTIONS, true)) {
            $out['options'] = $this->options($settings['options'] ?? []);
        }

        return $out === [] ? null : $out;
    }

    private function options(mixed $options): array
    {
        if (! is_array($options) || count($options) > 100) {
            throw ValidationException::withMessages(['settings.options' => 'Up to 100 options.']);
        }

        $out = [];
        foreach ($options as $i => $o) {
            $label = is_array($o) ? ($o['label'] ?? null) : null;
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw ValidationException::withMessages(["settings.options.{$i}.label" => 'Each option needs a label.']);
            }
            $id = is_string($o['id'] ?? null) && $o['id'] !== '' ? $o['id'] : Str::lower(Str::random(10));
            if (isset($out[$id])) {
                throw ValidationException::withMessages(["settings.options.{$i}.id" => 'Two options share an id.']);
            }
            $colour = $o['colour'] ?? null;
            $out[$id] = ['id' => $id, 'label' => trim($label), 'colour' => is_string($colour) ? mb_substr($colour, 0, 16) : null];
        }

        return array_values($out);
    }

    private function statusRules(bool $required): array
    {
        $req = $required ? ['required'] : ['sometimes'];

        return [
            'name' => [...$req, 'string', 'max:100'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:16'],
            'is_done' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function columnRules(bool $required): array
    {
        $req = $required ? ['required'] : ['sometimes'];

        return [
            'name' => [...$req, 'string', 'max:100'],
            'type' => [...$req, Rule::in(TaskColumn::TYPES)],
            'settings' => ['sometimes', 'nullable', 'array'],
            'hidden' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function groupRules(bool $required): array
    {
        $req = $required ? ['required'] : ['sometimes'];

        return [
            'name' => [...$req, 'string', 'max:100'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:16'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
