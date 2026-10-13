<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumnValue;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Task Board tasks. Every write goes through TaskWriter. */
class TasksController extends Controller
{
    /** What present() reads. */
    public const WITH = ['assignee:id,name', 'creator:id,name', 'statusRow', 'values.column'];

    /** The counts present() reads. A subtask is done when its status is (tasks.status maps every done status to `done`). */
    public static function counts(): array
    {
        return ['updates', 'subtasks', 'subtasks as subtasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE)];
    }

    public function __construct(private readonly TaskWriter $writer) {}

    public function show(Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $task->load(['assignee:id,name', 'creator:id,name', 'updates.user:id,name', 'statusRow', 'values.column'])->loadCount(['updates', 'subtasks']);

        return response()->json(['data' => self::present($task) + [
            'updates' => $task->updates->map(fn (TaskUpdate $u) => [
                'id' => $u->id,
                'body' => $u->body,
                'author' => $u->user ? ['id' => $u->user->id, 'name' => $u->user->name] : null,
                'created_at' => $u->created_at?->toIso8601String(),
            ])->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['board_id' => ['required', 'uuid'], 'parent_task_id' => ['sometimes', 'nullable', 'uuid']] + $this->rules(required: true));
        $board = TaskBoard::query()->findOrFail($input['board_id']);
        $this->authorize('update', $board);
        $this->checkReferences($input);
        $this->checkOnBoard($board, $input);

        $task = $this->writer->create($board, $this->toColumns($input), $request->user());

        return response()->json(['data' => self::present($task->fresh(self::WITH)->loadCount(self::counts()))], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);
        $input = $request->validate($this->rules(required: false));
        $this->checkReferences($input);
        if (($input['assignee_user_id'] ?? null) !== $task->assignee_user_id) {
            $this->checkOnBoard($task->board, $input);
        }

        $task = $this->writer->update($task, $this->toColumns($input), $request->user());

        return response()->json(['data' => self::present($task->fresh(self::WITH)->loadCount(self::counts()))]);
    }

    public function destroy(Request $request, Task $task): Response
    {
        $this->authorize('delete', $task);
        $this->writer->delete($task, $request->user());

        return response()->noContent();
    }

    public function storeUpdate(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);
        $input = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $update = $this->writer->comment($task, $request->user(), $input['body']);

        return response()->json(['data' => [
            'id' => $update->id,
            'body' => $update->body,
            'author' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'created_at' => $update->created_at?->toIso8601String(),
        ]], 201);
    }

    public static function present(Task $task): array
    {
        $about = $task->aboutRecord();

        return [
            'id' => $task->id,
            'board_id' => $task->board_id,
            'parent_task_id' => $task->parent_task_id,
            'group_id' => $task->group_id,
            'title' => $task->title,
            'status' => $task->status,
            'status_id' => $task->status_id,
            'values' => (object) $task->values->mapWithKeys(fn (TaskColumnValue $v) => [$v->column_id => self::valueOf($v)])->all(),
            'subtasks_count' => (int) ($task->subtasks_count ?? 0),
            'subtasks_done' => (int) ($task->subtasks_done ?? 0),
            'owner' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
            'due_on' => $task->due_on?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'about' => $about ? ['type' => $task->about_type, 'id' => $task->about_id, 'label' => self::aboutLabel($about)] : null,
            'updates_count' => (int) ($task->updates_count ?? 0),
            'created_by' => match (true) {
                $task->created_by_automation_id !== null => [
                    'type' => 'automation',
                    'label' => Automation::query()->find($task->created_by_automation_id)?->name ?? 'An automation',
                ],
                $task->creator !== null => ['type' => 'user', 'label' => $task->creator->name],
                default => null,
            },
        ];
    }

    /** A stored value as the client reads it, by its column's type. */
    public static function valueOf(TaskColumnValue $v): mixed
    {
        return match ($v->column?->type) {
            'number' => $v->value_number,
            'date' => $v->value_date?->toDateString(),
            'person' => $v->value_user_id,
            'checkbox' => (bool) $v->value_number,
            default => $v->value_text,
        };
    }

    private static function aboutLabel(Model $about): string
    {
        return match (true) {
            $about instanceof Job => 'Job #'.$about->number,
            $about instanceof Lead => $about->contact_name ?: 'A lead',
            $about instanceof Customer => $about->name ?: 'A customer',
            $about instanceof Vehicle => trim("{$about->year} {$about->make} {$about->model}") ?: ($about->plate ?? 'A vehicle'),
            default => '',
        };
    }

    private function rules(bool $required): array
    {
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'title' => [...$sometimes, 'required', 'string', 'max:255'],
            'group_id' => ['sometimes', 'nullable', 'uuid'],
            // A built-in key or a status name on the board; TaskWriter resolves it.
            'status' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status_id' => ['sometimes', 'nullable', 'uuid'],
            'values' => ['sometimes', 'array'],
            'assignee_user_id' => ['sometimes', 'nullable', 'uuid'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'about' => ['sometimes', 'nullable', 'array'],
            'about.type' => ['required_with:about', Rule::in(array_keys(Task::ABOUT))],
            'about.id' => ['required_with:about', 'uuid'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:16'],
            'estimate_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** Assignee and "about" must be records of this organization (tenant-scoped finds). */
    private function checkReferences(array $input): void
    {
        if (! empty($input['assignee_user_id']) && ! User::query()->whereKey($input['assignee_user_id'])->exists()) {
            throw ValidationException::withMessages(['assignee_user_id' => 'That person is not in this organization.']);
        }

        if (! empty($input['about'])) {
            $class = Task::ABOUT[$input['about']['type']];
            if (! $class::query()->whereKey($input['about']['id'])->exists()) {
                throw ValidationException::withMessages(['about.id' => 'That record was not found.']);
            }
        }
    }

    /**
     * A private board's tasks go to its owners and members only (inbox 0120).
     * Offering membership is the client's job; here the answer is plain.
     */
    private function checkOnBoard(TaskBoard $board, array $input): void
    {
        $id = $input['assignee_user_id'] ?? null;
        if ($id === null || ! $board->isPrivate()) {
            return;
        }
        if (! app(TaskBoards::class)->assignable($board)->contains('id', $id)) {
            $name = User::query()->whereKey($id)->value('name') ?? 'that person';
            throw ValidationException::withMessages(['assignee_user_id' => "Add {$name} to this board first."]);
        }
    }

    private function toColumns(array $input): array
    {
        if (array_key_exists('about', $input)) {
            $input['about_type'] = $input['about']['type'] ?? null;
            $input['about_id'] = $input['about']['id'] ?? null;
            unset($input['about']);
        }
        if (array_key_exists('status', $input) && $input['status'] === null) {
            unset($input['status']);
        }
        unset($input['board_id']);

        return $input;
    }
}
