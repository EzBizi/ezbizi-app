<?php

namespace App\Http\Controllers\Api;

use App\Models\Attachment;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Files\AttachmentUploader;
use App\Support\Files\UploadLimits;
use App\Support\Tasks\SensitiveTasks;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskLinks;
use App\Support\Tasks\TaskWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Task Board tasks. Every write goes through TaskWriter. */
class TasksController extends Controller
{
    /** What present() reads. */
    public const WITH = ['assignee:id,name', 'owners.user:id,name', 'creator:id,name', 'statusRow', 'values.column'];

    /** The counts present() reads. A subtask is done when its status is (tasks.status maps every done status to `done`). */
    public static function counts(): array
    {
        return ['updates', 'subtasks', 'subtasks as subtasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE)];
    }

    public function __construct(
        private readonly TaskWriter $writer,
        private readonly AttachmentUploader $uploader,
        private readonly UploadLimits $limits,
        private readonly SensitiveTasks $sensitive,
    ) {}

    /** A sensitive task comes back locked (no values, no updates) unless this session has unlocked it. */
    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $reveal = $this->sensitive->reveal($request, $task, 'task');

        return response()->json(['data' => $this->detail($task, $reveal)
            + ($task->sensitive && $reveal ? ['unlocked_until' => $this->sensitive->until($request)] : [])]);
    }

    /**
     * POST tasks/{task}/unlock {code}: a current authenticator code opens
     * sensitive tasks on this session for 5 minutes; answers this one, open.
     */
    public function unlock(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $until = $this->sensitive->unlock($request);

        return response()->json(['data' => $this->detail($task, $this->sensitive->reveal($request, $task, 'unlock'))
            + ['unlocked_until' => $until]]);
    }

    /** DELETE tasks/unlock: lock again now, on the server too. */
    public function lock(Request $request): Response
    {
        $this->sensitive->lock($request);

        return response()->noContent();
    }

    private function detail(Task $task, bool $reveal): array
    {
        $task->load(['assignee:id,name', 'owners.user:id,name', 'creator:id,name', 'updates.user:id,name', 'updates.attachments', 'statusRow', 'values.column'])->loadCount(['updates', 'subtasks']);

        return self::present($task, $reveal) + [
            'updates' => $reveal ? $task->updates->map(fn (TaskUpdate $u) => self::presentUpdate($u))->all() : [],
        ];
    }

    /**
     * An update and its files. `url` is the authorized file endpoint
     * (GET attachments/{id}, same bearer token, board `view` decides);
     * `?inline=1` opens an image or a PDF in place.
     */
    public static function presentUpdate(TaskUpdate $u): array
    {
        return [
            'id' => $u->id,
            'body' => (string) SensitiveTasks::open($u->body),
            'author' => $u->user ? ['id' => $u->user->id, 'name' => $u->user->name] : null,
            'created_at' => $u->created_at?->toIso8601String(),
            'attachments' => $u->attachments->sortBy('created_at')->values()->map(function (Attachment $a) {
                $url = url("/api/v1/attachments/{$a->id}");

                return [
                    'id' => $a->id,
                    'name' => $a->realName(),
                    'mime' => $a->mime,
                    'size' => (int) $a->size_bytes,
                    'url' => $url,
                    'inline_url' => in_array($a->mime, AttachmentsController::INLINE_MIMES, true) ? $url.'?inline=1' : null,
                    'thumbnail_url' => str_starts_with((string) $a->mime, 'image/') && in_array($a->mime, AttachmentsController::INLINE_MIMES, true) ? $url.'?inline=1' : null,
                ];
            })->all(),
        ];
    }

    /** Up to 10 files, 25 MB each. Extensions: the attachment list plus these. */
    public const UPDATE_MAX_FILES = 10;

    public const UPDATE_MAX_KB = 25600;

    public const UPDATE_EXTRA_EXTENSIONS = ['gif', 'ppt', 'pptx', 'mp4', 'mov', 'webm', 'm4v'];

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['board_id' => ['required', 'uuid'], 'parent_task_id' => ['sometimes', 'nullable', 'uuid']] + $this->rules(required: true));
        $board = TaskBoard::query()->findOrFail($input['board_id']);
        $this->authorize('update', $board);
        $this->checkReferences($input);
        $this->checkOnBoard($board, $input);

        $task = $this->writer->create($board, $this->toColumns($input), $request->user());

        return response()->json(['data' => self::present($task->fresh(self::WITH)->loadCount(self::counts()),
            $this->sensitive->reveal($request, $task, 'create'))], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);
        $input = $request->validate($this->rules(required: false));
        $this->checkReferences($input);
        if (($input['assignee_user_id'] ?? null) !== $task->assignee_user_id) {
            $this->checkOnBoard($task->board, $input);
        }

        // Anyone who can edit it marks it; unmarking opens everything, so it
        // takes a fresh authenticator code like a reveal does.
        if (array_key_exists('sensitive', $input)) {
            $want = (bool) $input['sensitive'];
            unset($input['sensitive']);
            if ($want !== (bool) $task->sensitive) {
                if (! $want && ! $this->sensitive->unlocked($request)) {
                    throw SensitiveTasks::lockedResponse();
                }
                $this->sensitive->mark($task, $want, $request->user());
                $task->refresh();
            }
        }

        $task = $this->writer->update($task, $this->toColumns($input), $request->user());

        return response()->json(['data' => self::present($task->fresh(self::WITH)->loadCount(self::counts()),
            $this->sensitive->reveal($request, $task, 'edit'))]);
    }

    public function destroy(Request $request, Task $task): Response
    {
        $this->authorize('delete', $task);
        $this->writer->delete($task, $request->user());

        return response()->noContent();
    }

    /** PATCH tasks/{task}/move {group_id, position?}: reorder in a group, or move to another group or board. */
    public function move(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);
        $input = $request->validate(['group_id' => ['required', 'uuid'], 'position' => ['sometimes', 'nullable', 'integer', 'min:0']]);
        $group = $this->writableGroup($request->user(), $input['group_id']);
        $task = $this->writer->move($task, $group, $input['position'] ?? null, $request->user());

        return response()->json(['data' => self::present($task->fresh(self::WITH)->loadCount(self::counts()))]);
    }

    /** POST tasks/{task}/copy {group_id?}: a copy in that group (any board you write), or a duplicate beside it. */
    public function copy(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $input = $request->validate(['group_id' => ['sometimes', 'nullable', 'uuid']]);
        $group = $this->writableGroup($request->user(), $input['group_id'] ?? $task->group_id);
        $copy = $this->writer->copy($task, $group, $request->user());

        return response()->json(['data' => self::present($copy->fresh(self::WITH)->loadCount(self::counts()))], 201);
    }

    /** POST tasks/{id}/restore: undo a delete. */
    public function restore(Request $request, string $id): JsonResponse
    {
        $task = Task::onlyTrashed()->findOrFail($id);
        $this->authorize('update', $task);
        $this->writer->restore($task, $request->user());

        return response()->json(['data' => self::present($task->fresh(self::WITH)->loadCount(self::counts()))]);
    }

    /**
     * POST tasks/bulk {ids, op: move|copy|delete|status|assign, group_id?, status?, assignee_user_id?}.
     * Each task is checked on its own; one refused does not stop the rest.
     */
    public function bulk(Request $request): JsonResponse
    {
        $input = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['uuid'],
            'op' => ['required', Rule::in(['move', 'copy', 'delete', 'status', 'assign'])],
            'group_id' => [Rule::requiredIf(in_array($request->input('op'), ['move', 'copy'], true)), 'nullable', 'uuid'],
            'status' => [Rule::requiredIf($request->input('op') === 'status'), 'nullable', 'string', 'max:64'],
            'assignee_user_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $user = $request->user();
        $group = in_array($input['op'], ['move', 'copy'], true) ? $this->writableGroup($user, $input['group_id']) : null;
        $done = [];
        $refused = [];

        foreach (array_values(array_unique($input['ids'])) as $id) {
            $task = Task::query()->find($id);
            $ability = $input['op'] === 'copy' ? 'view' : ($input['op'] === 'delete' ? 'delete' : 'update');
            if ($task === null || ! $user->can($ability, $task)) {
                $refused[] = ['id' => $id, 'reason' => 'You cannot change that task.'];

                continue;
            }
            try {
                match ($input['op']) {
                    'move' => $this->writer->move($task, $group, null, $user),
                    'copy' => $this->writer->copy($task, $group, $user),
                    'delete' => $this->writer->delete($task, $user),
                    'status' => $this->writer->update($task, ['status' => $input['status']], $user),
                    'assign' => $this->writer->update($task, ['assignee_user_id' => $input['assignee_user_id'] ?? null], $user),
                };
                $done[] = $id;
            } catch (ValidationException $e) {
                $refused[] = ['id' => $id, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['data' => ['done' => $done, 'refused' => $refused]]);
    }

    /** A group on a board the person may write. */
    private function writableGroup(User $user, string $groupId): TaskGroup
    {
        $group = TaskGroup::query()->with('board')->find($groupId);
        if ($group === null || $group->board === null || ! $user->can('view', $group->board)) {
            throw ValidationException::withMessages(['group_id' => 'There is no such group you can see.']);
        }
        $this->authorize('update', $group->board);

        return $group;
    }

    /** GET tasks/{task}/links: the linked tasks this person can see. */
    public function links(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $user = $request->user();

        return response()->json(['data' => TaskLinks::of($task)
            ->filter(fn (Task $t) => $user->can('view', $t))
            ->map(fn (Task $t) => self::presentLink($t))->values()->all()]);
    }

    /** POST tasks/{task}/links {linked_task_id}: both must be visible to the person. */
    public function storeLink(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $input = $request->validate(['linked_task_id' => ['required', 'uuid']]);
        $other = Task::query()->find($input['linked_task_id']);
        if ($other === null || ! $request->user()->can('view', $other)) {
            throw ValidationException::withMessages(['linked_task_id' => 'There is no such task you can see.']);
        }
        if ($other->id === $task->id) {
            throw ValidationException::withMessages(['linked_task_id' => 'A task cannot be linked to itself.']);
        }
        $created = TaskLinks::link($task, $other, $request->user());

        return response()->json(['data' => self::presentLink($other->load('board'))], $created ? 201 : 200);
    }

    public function destroyLink(Request $request, Task $task, string $linked): Response
    {
        $this->authorize('view', $task);
        $other = Task::query()->findOrFail($linked);
        $this->authorize('view', $other);
        TaskLinks::unlink($task, $other);

        return response()->noContent();
    }

    private static function presentLink(Task $t): array
    {
        return ['id' => $t->id, 'title' => $t->title, 'sensitive' => (bool) $t->sensitive, 'status' => $t->status, 'board_id' => $t->board_id, 'board_name' => $t->board?->name];
    }

    public function storeUpdate(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        // A file PHP refused for its size arrives present and invalid; say so
        // in words before `max:` (which cannot weigh it) does. See UploadLimits.
        foreach ((array) $request->file('files', []) as $i => $file) {
            if ($file instanceof UploadedFile && ! $file->isValid()
                && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw ValidationException::withMessages(["files.$i" => 'That file is too large. The most an update takes is 25 MB per file.']);
            }
        }

        $extensions = array_values(array_unique([...$this->limits->extensions(), ...self::UPDATE_EXTRA_EXTENSIONS]));
        $input = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:files'],
            'files' => ['sometimes', 'array', 'max:'.self::UPDATE_MAX_FILES],
            'files.*' => ['file', 'mimes:'.implode(',', $extensions), 'max:'.self::UPDATE_MAX_KB],
            'mentions' => ['sometimes', 'array', 'max:50'],
            'mentions.*' => ['uuid'],
        ], [
            'body.required_without' => 'Write something or attach a file.',
            'files.max' => 'An update takes at most '.self::UPDATE_MAX_FILES.' files.',
            'files.*.max' => 'That file is too large. The most an update takes is 25 MB per file.',
            'files.*.mimes' => 'That kind of file is not accepted here. Accepted: '.implode(', ', $extensions).'.',
        ]);
        $body = trim((string) ($input['body'] ?? ''));
        $files = $input['files'] ?? [];
        if ($body === '' && $files === []) {
            throw ValidationException::withMessages(['body' => 'Write something or attach a file.']);
        }

        $update = DB::transaction(function () use ($task, $request, $body, $files, $input) {
            // A mention on a sensitive task says only that there is something to read.
            $mentions = app(\App\Support\Notes\NoteActivity::class)->mention($task, $input['mentions'] ?? [],
                $request->user(), $task->sensitive ? SensitiveTasks::NOTICE : $body);
            $update = $this->writer->comment($task, $request->user(), $body, $mentions);
            foreach ($files as $file) {
                $this->uploader->upload($request->user(), $update, $file, 'task_updates');
            }

            return $update;
        });

        return response()->json(['data' => self::presentUpdate($update->load(['user:id,name', 'attachments']))], 201);
    }

    /**
     * A task as the board reads it. A sensitive one is `locked` (no values)
     * unless the caller passed SensitiveTasks::reveal for it; lists never do.
     */
    public static function present(Task $task, bool $reveal = false): array
    {
        $about = $task->aboutRecord();
        $locked = $task->sensitive && ! $reveal;

        return [
            'id' => $task->id,
            'board_id' => $task->board_id,
            'parent_task_id' => $task->parent_task_id,
            'group_id' => $task->group_id,
            'title' => $task->title,
            'sensitive' => (bool) $task->sensitive,
            'locked' => $locked,
            'status' => $task->status,
            'status_id' => $task->status_id,
            // A roll-up shows a branch's task on its company board, whose statuses differ: matched by name.
            'status_name' => $task->statusRow?->name,
            'values' => $locked ? (object) [] : (object) $task->values->mapWithKeys(fn (TaskColumnValue $v) => [$v->column_id => self::valueOf($v)])->all(),
            'subtasks_count' => (int) ($task->subtasks_count ?? 0),
            'subtasks_done' => (int) ($task->subtasks_done ?? 0),
            'owner' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
            // Every owner, the primary (`owner`) first.
            'owners' => $task->owners->filter(fn ($o) => $o->user !== null)
                ->map(fn ($o) => ['id' => $o->user->id, 'name' => $o->user->name])->values()->all(),
            'created_at' => $task->created_at?->toIso8601String(),
            'due_on' => $task->due_on?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'about' => $about ? ['type' => $task->about_type, 'id' => $task->about_id, 'label' => self::aboutLabel($about)] : null,
            'updates_count' => (int) ($task->updates_count ?? 0),
            'created_by' => match (true) {
                $task->created_by_automation_id !== null => [
                    'type' => 'automation',
                    'label' => Automation::query()->find($task->created_by_automation_id)?->name ?? 'An automation',
                ],
                $task->creator !== null => ['type' => 'user', 'id' => $task->creator->id, 'label' => $task->creator->name],
                default => null,
            },
        ];
    }

    /** GET tasks/{task}/activity: its Activity log, newest first (Jose, 2026-11-09). */
    public function activity(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        // A sensitive task's diffs are its values: locked rows say who, what and when only.
        $locked = ! $this->sensitive->reveal($request, $task, 'activity');
        $hide = fn ($a) => $locked && $a->field !== 'sensitive';

        return response()->json(['data' => \App\Models\TaskActivity::query()->where('task_id', $task->id)
            ->with('user:id,name')->latest('created_at')->limit(200)->get()
            ->map(fn ($a) => ['id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'who' => $a->user?->name,
                'field' => $a->field, 'label' => $a->label, 'locked' => $hide($a),
                'from' => $hide($a) ? null : SensitiveTasks::open($a->from),
                'to' => $hide($a) ? null : SensitiveTasks::open($a->to)])->values()]);
    }

    /** A stored value as the client reads it, by its column's type. */
    public static function valueOf(TaskColumnValue $v): mixed
    {
        // Opened here; whether a sensitive task's values go out at all is present()'s call.
        $text = SensitiveTasks::open($v->value_text);

        return match ($v->column?->type) {
            'number', 'currency' => $v->value_number,
            'tags' => json_decode((string) $text, true) ?: [],
            'link' => TaskWriter::linkOf($text),
            'date' => $v->value_date?->toDateString(),
            // Stored as its start (value_date) and end (value_text).
            'timeline' => $v->value_date ? ['from' => $v->value_date->toDateString(), 'to' => $text] : null,
            'person' => $v->value_user_id,
            'checkbox' => (bool) $v->value_number,
            default => $text,
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
            // A group NAME, instead of group_id; TaskWriter resolves it.
            'group' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Every owner, primary first; TaskWriter checks each can be named here.
            'assignee_user_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'assignee_user_ids.*' => ['uuid', 'distinct'],
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
            'sensitive' => ['sometimes', 'boolean'],
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
