<?php

namespace App\Support\Tasks\Monday;

use App\Jobs\MakeVideoPoster;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskBoardFolder;
use App\Models\TaskBoardView;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Files\AttachmentUploader;
use App\Support\Tasks\ColumnConverter;
use App\Support\Tasks\SensitiveTasks;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Monday items onto the Task Board, from the export shape in
 * docs/agents/monday-sync-design-2026-11-12.md. One code path for the one-off
 * import (ezbizi:tasks-import-monday, a JSON file) and the sync
 * (ezbizi:monday-sync and the webhook, Monday read live): the sync is an import
 * that also refreshes what it already brought.
 *
 * A personal board (owner_email) lands on that person's own board, which then
 * carries external_ref monday:<board id> so the sync can find it; any other
 * becomes a main board of the same name. Groups, statuses and columns are made
 * by name where missing. Subitems become subtasks; updates and their replies
 * become updates with Monday's dates, their files attachments. People are
 * matched through the `people` map (monday email => EzBizi email), then by
 * email, then by an unambiguous name.
 *
 * Done: an item marked `done` lands on a done status (made done when new)
 * with Monday's completion time.
 *
 * Idempotent on external_ref monday:<id>. A task deleted here (the 11-03 run,
 * deleted 11-09) is restored, moved and refreshed instead of colliding on the
 * unique key. No automation runs for imported or synced tasks.
 *
 * Refresh (the sync): an existing task takes Monday's fields when Monday's
 * item changed since we last took it, unless the task was also changed here
 * since, and later than Monday — last writer wins, by timestamp.
 */
final class MondayImport
{
    /** Group names whose items are done even with a blank status. */
    public const DONE_GROUPS = ['done', 'completed', 'completed tasks', 'completed hirings', 'ezbizi - done'];

    /**
     * Off the upload list but safe to stream and play, so they keep a playable
     * type and the Gallery plays them (desk, 2026-11-12: Rodrigo's videos had
     * landed as octet-stream). Everything else off the list stays a download.
     */
    public const STREAMABLE = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/x-m4v',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];

    /** @var array<string, User> lower-cased email => user */
    private array $people = [];

    /** @var array<string, string> lower-cased monday email => lower-cased EzBizi email */
    private array $map = [];

    /** @var array<string, string> lower-cased monday email => monday name */
    private array $mondayNames = [];

    private array $counts = [];

    /** @var array<string, true> */
    private array $unmatched = [];

    /** @var array<string, int> name => tasks they were dropped from */
    private array $ownersDropped = [];

    /** @var list<string> */
    private array $notCarried = [];

    /** @var array<string, list<string>> board id => user ids it can take as owners */
    private array $assignable = [];

    /** @var list<string> the current item's Monday assignees this board cannot take, by name */
    private array $asText = [];

    /** @var list<string> monday item ids an operator named to mark Sensitive (--sensitive-items) */
    private array $sensitiveItems = [];

    private bool $dry = false;

    private bool $refresh = false;

    private ?string $filesDir = null;

    private TaskWriter $writer;

    public function __construct(
        private readonly TaskBoards $boards,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  string|User  $as  the person the import acts as: a user, or an email the people map resolves
     * @return array{counts: array<string, int|float>, unmatched: list<string>, owners_dropped: array<string, int>, not_carried: list<string>}
     */
    public function run(Organization $org, array $data, string|User $as, bool $dry = false, ?string $filesDir = null, bool $refresh = false, array $sensitiveItems = []): array
    {
        $this->reset($data, $dry, $filesDir, $refresh);
        $this->sensitiveItems = array_map('strval', $sensitiveItems);

        // Imports are history, not events: no rule texts anyone about them.
        // Put back afterwards, so a queue worker's next job has its events.
        app()->instance(EventEmitter::class, new class extends EventEmitter
        {
            public function __construct() {}

            public function emitAfterCommit(string $eventKey, ?Model $subject = null, array $payload = []): void {}
        });
        $this->writer = app()->make(TaskWriter::class);

        DB::beginTransaction();
        try {
            // What Monday already has is never written back to it (MondayOutbox).
            MondayOutbox::suppress(fn () => $this->tenant->run($org, function () use ($data, $as) {
                $this->people = User::query()->get()->keyBy(fn (User $u) => mb_strtolower($u->email))->all();
                $actor = $as instanceof User ? $as : ($this->person($as)
                    ?? throw new \RuntimeException('--as must be the email of a user in this organization.'));
                foreach ($data['boards'] as $b) {
                    $board = $this->board($b, $actor);
                    $this->layout($board, $b);
                    $this->folder($board, $b['folder'] ?? null);
                    foreach ($b['items'] as $item) {
                        $parent = $this->task($board, $item, null, $actor);
                        foreach ($item['subitems'] ?? [] as $sub) {
                            $this->task($board, $sub + ['group' => $item['group']], $parent, $actor);
                        }
                    }
                }
            }));
            $this->dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            app()->forgetInstance(EventEmitter::class);
        }

        $this->counts['files MB'] = round($this->counts['files MB'], 1);

        return [
            'counts' => $this->counts,
            'unmatched' => array_keys($this->unmatched),
            'owners_dropped' => $this->ownersDropped,
            'not_carried' => $this->notCarried,
        ];
    }

    private function reset(array $data, bool $dry, ?string $filesDir, bool $refresh): void
    {
        $this->dry = $dry;
        $this->refresh = $refresh;
        $this->filesDir = $filesDir;
        $this->counts = [
            'boards made' => 0, 'tasks open' => 0, 'tasks done' => 0, 'subtasks open' => 0, 'subtasks done' => 0,
            'restored (deleted earlier)' => 0, 'skipped (already imported)' => 0, 'refreshed from monday' => 0,
            'kept (changed here later)' => 0, 'updates' => 0, 'updates already there' => 0, 'files' => 0,
            'files MB' => 0.0, 'files not carried' => 0, 'statuses made' => 0, 'groups made' => 0, 'columns made' => 0,
            'assignees kept as text' => 0, 'columns retyped' => 0, 'values filled' => 0, 'values not carried' => 0,
        ];
        $this->unmatched = $this->ownersDropped = $this->notCarried = $this->assignable = $this->map = $this->mondayNames = [];
        foreach ($data['people'] ?? [] as $from => $to) {
            $this->map[mb_strtolower(trim($from))] = mb_strtolower(trim($to));
        }
        foreach ($data['users'] ?? [] as $u) {
            if (! empty($u['email']) && ! empty($u['name'])) {
                $this->mondayNames[mb_strtolower(trim($u['email']))] = $u['name'];
            }
        }
    }

    private function board(array $b, User $as): TaskBoard
    {
        $ref = 'monday:'.$b['monday_id'];
        if (! empty($b['target_board_id'])) {
            return TaskBoard::query()->findOrFail($b['target_board_id']);
        }
        if ($owner = $this->person($b['owner_email'] ?? null)) {
            $board = $this->boards->ensureFor($owner);
            self::claim($board, $ref);

            return $board;
        }
        $board = TaskBoard::query()->withTrashed()->where('external_ref', $ref)->first();
        if ($board?->trashed()) {
            $board->restore();
        }
        if ($board === null) {
            // "As we have it on Monday" (Jose, 11-13): a board of the same name, on the
            // branch or company the map names (else the organization), main or private.
            [$company, $franchise] = $this->place($b['scope'] ?? []);
            $visibility = ($b['visibility'] ?? TaskBoard::MAIN) === TaskBoard::PRIVATE ? TaskBoard::PRIVATE : TaskBoard::MAIN;
            $board = $this->boards->createCustom($as, mb_substr((string) $b['name'], 0, 255), $visibility, $company, $franchise);
            $board->forceFill(['external_ref' => $ref])->saveQuietly();
            $this->counts['boards made']++;
        }
        if (! empty($b['archived']) && $board->archived_at === null) {
            $board->forceFill(['archived_at' => now()])->saveQuietly(); // archived on Monday (Toula)
        }
        $this->members($board, $b['members'] ?? []);

        return $board;
    }

    /**
     * The company/franchise a board lands on, by name as the map writes it
     * ({company: "A-1 Chimney", franchise: "Illinois"}). A name that names
     * nothing here stops the import: a board must not land on the wrong place.
     *
     * @return array{0: ?\App\Models\Company, 1: ?\App\Models\Franchise}
     */
    private function place(array $scope): array
    {
        $find = function (string $model, string $name, ?string $companyId = null) {
            $rows = $model::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->get()
                ->filter(fn ($r) => mb_strtolower(trim((string) $r->name)) === mb_strtolower(trim($name)));

            return $rows->count() === 1 ? $rows->first()
                : throw new \RuntimeException(sprintf('The map names %s "%s", which is %s here.', class_basename($model), $name, $rows->isEmpty() ? 'nothing' : 'more than one place'));
        };
        $company = ! empty($scope['company']) ? $find(\App\Models\Company::class, (string) $scope['company']) : null;
        $franchise = ! empty($scope['franchise']) ? $find(\App\Models\Franchise::class, (string) $scope['franchise'], $company?->id) : null;

        return [$company, $franchise];
    }

    /**
     * Monday's owners and subscribers become the board's owners and members
     * (a custom board only: a personal board is its person's). Added, never
     * removed; somebody with no account here is counted, not invented.
     */
    private function members(TaskBoard $board, array $members): void
    {
        if ($board->kind !== TaskBoard::KIND_CUSTOM || $members === []) {
            return;
        }
        $have = \App\Models\TaskBoardMember::query()->where('board_id', $board->id)->pluck('role', 'user_id')->all();
        foreach ($members as $m) {
            $user = $this->person($m['email'] ?? null);
            if ($user === null || ! $user->is_active || isset($have[$user->id])) {
                continue;
            }
            $role = ($m['role'] ?? '') === 'owner' ? \App\Models\TaskBoardMember::OWNER : \App\Models\TaskBoardMember::MEMBER;
            \App\Models\TaskBoardMember::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id, 'user_id' => $user->id, 'role' => $role]);
            $have[$user->id] = $role;
            $this->counts['members added'] = ($this->counts['members added'] ?? 0) + 1;
        }
        unset($this->assignable[$board->id]);
    }

    /**
     * A personal board answers for its monday board from now on, so the sync
     * finds it. A DELETED board holding the key (the 11-03 run's custom board)
     * gives it up; a live one keeps it.
     */
    public static function claim(TaskBoard $board, string $ref): void
    {
        if ($board->external_ref !== null) {
            return;
        }
        $holder = TaskBoard::query()->withTrashed()->where('external_ref', $ref)->whereKeyNot($board->id)->first();
        if ($holder !== null && ! $holder->trashed()) {
            return;
        }
        $holder?->forceFill(['external_ref' => null])->saveQuietly();
        $board->forceFill(['external_ref' => $ref])->saveQuietly();
    }

    private function task(TaskBoard $board, array $item, ?Task $parent, User $as): ?Task
    {
        $ref = 'monday:'.$item['monday_id'];
        $existing = Task::query()->withTrashed()->where('external_ref', $ref)->first();
        if ($existing !== null && ! $existing->trashed()) {
            $this->refreshed($existing, $board, $item, $parent, $as);
            $this->fillBlanks($existing->refresh(), $board, $item, $as);
            $this->owners($board, $item['people'] ?? []);
            $this->assignedInMonday($existing, $as);
            $this->updates($existing, $item, $as);
            $this->fileColumns($existing, $board, $item, $as);
            $this->markSensitive($existing, $item, $parent, $as);

            return $existing;
        }

        $input = $this->input($board, $item);
        $task = $existing !== null
            ? $this->reuse($existing, $board, $parent, $input, $as)
            : $this->writer->create($board, $input + ['parent_task_id' => $parent?->id], $as);

        $created = isset($item['created_at']) ? CarbonImmutable::parse($item['created_at']) : null;
        $task->forceFill(array_filter([
            'external_ref' => $ref,
            'created_by_user_id' => $this->person($item['creator_email'] ?? null)?->id ?? $as->id,
            'created_at' => $created,
        ]))->saveQuietly();
        $isDone = $this->taken($task, $item);
        $this->counts[($parent ? 'subtasks' : 'tasks').($isDone ? ' done' : ' open')]++;
        $this->assignedInMonday($task, $as);

        $this->updates($task, $item, $as);
        $this->fileColumns($task, $board, $item, $as);
        $this->markSensitive($task, $item, $parent, $as);

        return $task;
    }

    /**
     * Sensitive tasks (D13, Jose 2026-11-12), on the import and on every
     * sync: a name or group that reads like credentials, an id the operator
     * named, or a sensitive parent. After the updates and files, so mark()
     * seals what this run brought in. A task somebody here already decided
     * (an Activity row for Sensitive, e.g. unmarked with the authenticator)
     * is left as they left it, unless the operator named it.
     */
    private function markSensitive(Task $task, array $item, ?Task $parent, User $as): void
    {
        $sensitive = app(SensitiveTasks::class);
        if ($task->sensitive) {
            $sensitive->mark($task, true, $as); // seals whatever this run added

            return;
        }
        $named = in_array((string) ($item['monday_id'] ?? ''), $this->sensitiveItems, true);
        $decided = TaskActivity::query()->where('task_id', $task->id)->where('field', 'sensitive')->exists();
        if ($named || (! $decided && ($parent?->sensitive
            || SensitiveTasks::looksSensitive((string) ($item['name'] ?? ''), (string) ($item['group'] ?? ''))))) {
            $sensitive->mark($task, true, $as);
            $this->counts['marked sensitive'] = ($this->counts['marked sensitive'] ?? 0) + 1;
        }
    }

    /**
     * An item already here. The import leaves it as it is (and notes which
     * Monday state it holds, for the sync); the sync takes Monday's change
     * under the conflict rule.
     */
    private function refreshed(Task $task, TaskBoard $board, array $item, ?Task $parent, User $as): void
    {
        $mondayAt = isset($item['updated_at']) ? CarbonImmutable::parse($item['updated_at']) : null;
        if (! $this->refresh || $mondayAt === null) {
            $this->counts['skipped (already imported)']++;
            if ($task->external_updated_at === null && $mondayAt !== null) {
                // Imported before the sync existed: record the Monday state it holds.
                $this->quietly($task, ['external_updated_at' => $mondayAt, 'external_synced_at' => $task->updated_at]);
            }

            return;
        }

        $mondayChanged = $task->external_updated_at === null
            ? $mondayAt->greaterThan($task->updated_at)
            : $mondayAt->greaterThan($task->external_updated_at);
        if (! $mondayChanged) {
            $this->counts['skipped (already imported)']++;

            return;
        }
        $hereChanged = $task->external_synced_at !== null && $task->updated_at->greaterThan($task->external_synced_at);
        if ($hereChanged && ! $mondayAt->greaterThan($task->updated_at)) {
            // Both changed, and the change here is the later one: it stays.
            $this->counts['kept (changed here later)']++;
            $this->quietly($task, ['external_updated_at' => $mondayAt]);

            return;
        }

        $input = $this->input($board, $item);
        if ($parent !== null || $task->parent_task_id !== null) {
            unset($input['group']); // a subtask stays in its parent's group
        }
        $this->writer->update($task->load('board'), $input, $as);
        $this->taken($task->refresh(), $item);
        $this->counts['refreshed from monday']++;
    }

    /** Monday's completion time and the state it was taken from, without touching updated_at's meaning. */
    private function taken(Task $task, array $item): bool
    {
        $isDone = (bool) $task->statusRow?->is_done;
        $now = now();
        $this->quietly($task, [
            'completed_at' => $isDone ? (isset($item['completed_at']) ? CarbonImmutable::parse($item['completed_at']) : $task->completed_at) : null,
            'external_updated_at' => isset($item['updated_at']) ? CarbonImmutable::parse($item['updated_at']) : null,
            'external_synced_at' => $now,
            'updated_at' => $now,
        ]);

        return $isDone;
    }

    private function quietly(Task $task, array $fields): void
    {
        $task->timestamps = false;
        try {
            $task->forceFill($fields)->saveQuietly();
        } finally {
            $task->timestamps = true;
        }
    }

    /** The task's fields as TaskWriter takes them. Makes the statuses, groups and columns it names. */
    private function input(TaskBoard $board, array $item): array
    {
        // Ohio's board has no Status column; its Job Status is the status.
        $columns = $item['columns'] ?? [];
        if (empty($item['status']) && ! empty($columns['Job Status'])) {
            $item['status'] = is_array($columns['Job Status']) ? ($columns['Job Status']['value'] ?? null) : $columns['Job Status'];
            unset($columns['Job Status']);
        }
        $done = (bool) ($item['done'] ?? false)
            || (trim((string) ($item['status'] ?? '')) === '' && in_array(mb_strtolower(trim((string) $item['group'])), self::DONE_GROUPS, true));

        $values = [];
        foreach ($columns as $title => $value) {
            [$type, $value, $settings] = is_array($value) && array_key_exists('value', $value)
                ? [$value['type'] ?? 'text', $value['value'], (array) ($value['settings'] ?? [])]
                : ['text', $value, []];
            if ($value !== null && $value !== '' && $value !== []) {
                $column = $this->column($board, (string) $title, $type, $settings);
                $values[$column->id] = $this->valueFor($board, $column, $value);
            }
        }
        $due = $item['due_on'] ?? null;
        if (! empty($item['timeline']['from']) && ! empty($item['timeline']['to'])) {
            $due ??= $item['timeline']['to'];
            $column = $this->column($board, 'Timeline', 'timeline');
            $values[$column->id] = $this->valueFor($board, $column, ['from' => $item['timeline']['from'], 'to' => $item['timeline']['to']]);
        }

        return [
            'title' => mb_substr(trim($item['name']) ?: '(untitled)', 0, 255),
            'group' => $this->group($board, $item['group'])->name,
            'status' => $this->status($board, $item['status'] ?? null, $done),
            'priority' => isset($item['priority']) ? mb_substr($item['priority'], 0, 16) : null,
            'due_on' => $due,
            'values' => array_filter($values, fn ($v) => $v !== null),
            'assignee_user_ids' => $this->owners($board, $item['people'] ?? []),
        ];
    }

    /**
     * The 11-03 run's row for this monday item, deleted on 11-09: bring it
     * back onto $board and refresh it, so the unique key is reused, not hit.
     */
    private function reuse(Task $task, TaskBoard $board, ?Task $parent, array $input, User $as): Task
    {
        $task->restore();
        $this->counts['restored (deleted earlier)']++;
        if ($parent !== null) {
            unset($input['group']); // a subtask stays in its parent's group
            $task->forceFill([
                'parent_task_id' => $parent->id, 'board_id' => $parent->board_id, 'group_id' => $parent->group_id,
                'company_id' => $parent->company_id, 'franchise_id' => $parent->franchise_id,
            ])->saveQuietly();
        } elseif ($task->board_id !== $board->id || $task->parent_task_id !== null) {
            $task->forceFill(['parent_task_id' => null])->saveQuietly();
            $this->writer->move($task, $this->group($board, $input['group']), null, $as);
        }
        // Values on the old board's columns mean nothing here.
        TaskColumnValue::query()->where('task_id', $task->id)
            ->whereNotIn('column_id', TaskColumn::query()->where('board_id', $task->board_id)->select('id'))->delete();
        // A status from the old board would not resolve; the import's status (or the board default) replaces it.
        $input['status'] ??= $board->statuses()->where('key', Task::STATUS_NOT_STARTED)->value('name') ?? $board->statuses()->value('name');
        $task->load('board');

        return $this->writer->update($task, $input, $as)->refresh();
    }

    /**
     * Owners this board can take. The others are kept as words, never silently
     * lost: somebody this board cannot take (an admin on another person's
     * board; Jose, 2026-11-12, declined adding them as members) or nobody here
     * at all. assignedInMonday() writes them on the task.
     */
    private function owners(TaskBoard $board, array $emails): array
    {
        $this->assignable[$board->id] ??= $this->boards->assignable($board)->pluck('id')->all();
        $out = [];
        $this->asText = [];
        foreach ($emails as $email) {
            $user = $this->person($email);
            if ($user !== null && $user->is_active && $board->kind === TaskBoard::KIND_CUSTOM
                && ! in_array($user->id, $this->assignable[$board->id], true)) {
                // Assigned on Monday's board, so on it here: a custom board takes them as a member.
                $this->members($board, [['email' => $user->email, 'role' => 'member']]);
                $this->assignable[$board->id] = $this->boards->assignable($board)->pluck('id')->all();
            }
            if ($user !== null && in_array($user->id, $this->assignable[$board->id], true)) {
                $out[] = $user->id;

                continue;
            }
            $name = $user?->name ?? $this->mondayNames[mb_strtolower(trim((string) $email))]
                ?? (preg_match('/^(\d+)@deleted\.user$/', (string) $email, $m) ? 'former Monday user #'.$m[1] : (string) $email);
            $this->asText[] = $name;
            $this->ownersDropped[$name] = ($this->ownersDropped[$name] ?? 0) + 1;
        }
        $this->asText = array_values(array_unique($this->asText));

        return array_values(array_unique($out));
    }

    /** "Assigned in Monday: <name>" in the task's Activity, once per name, for owners kept as words. */
    private function assignedInMonday(Task $task, User $as): void
    {
        foreach ($this->asText as $name) {
            $said = TaskActivity::query()->where('task_id', $task->id)->where('field', 'monday_assignee')->where('to', $name)->exists();
            if (! $said) {
                TaskActivity::query()->create([
                    'organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => $as->id,
                    'field' => 'monday_assignee', 'label' => 'Assigned in Monday', 'from' => null, 'to' => mb_substr($name, 0, 255),
                ]);
                $this->counts['assignees kept as text']++;
            }
        }
        $this->asText = [];
    }

    /**
     * A Monday file column: its files came on the task's "Files from Monday"
     * update (they must be attachments before a Files value can name them);
     * the column then lists them, by name. A value set here stays.
     */
    private function fileColumns(Task $task, TaskBoard $board, array $item, User $as): void
    {
        foreach ($item['columns'] ?? [] as $title => $value) {
            if (! is_array($value) || ($value['type'] ?? null) !== 'files' || $this->dry) {
                continue;
            }
            $column = $this->column($board, (string) $title, 'files');
            if ($column->type !== 'files' || TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $column->id)->exists()) {
                continue;
            }
            $update = TaskUpdate::query()->where('external_ref', 'monday:files.'.$item['monday_id'])->first();
            $ids = $update ? $update->attachments()->whereIn('original_name', (array) $value['value'])->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
            if ($ids !== []) {
                $this->writer->update($task->load('board'), ['values' => [$column->id => $ids]], $as);
                $this->quietly($task->refresh(), ['external_synced_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function updates(Task $task, array $item, User $as): void
    {
        foreach ($item['updates'] ?? [] as $u) {
            $this->update($task, $u, 'monday:'.$u['monday_id'], $as);
            foreach ($u['replies'] ?? [] as $i => $r) {
                // The 11-03 run keyed a reply by its position; now by its own id.
                $ref = 'monday:'.$u['monday_id'].'.'.($r['monday_id'] ?? $i);
                $legacy = 'monday:'.$u['monday_id'].'.'.$i;
                $this->update($task, $r, $ref, $as, true, $legacy);
            }
        }
    }

    private function update(Task $task, array $u, string $ref, User $as, bool $reply = false, ?string $legacy = null): void
    {
        $files = $u['files'] ?? [];
        $existing = TaskUpdate::query()->whereIn('external_ref', array_filter([$ref, $legacy]))->first();
        if ($existing !== null) {
            $this->counts['updates already there']++;
            $this->files($existing, $files, $as, true);

            return;
        }
        $body = trim((string) ($u['body'] ?? ''));
        if ($body === '' && $files === []) {
            return;
        }
        $author = $this->person($u['author_email'] ?? null);
        if ($author === null && ! empty($u['author_email'])) {
            $who = ! empty($u['author_name']) && $u['author_name'] !== 'Deleted member' ? $u['author_name'] : $u['author_email'];
            $body = $who.' (on Monday): '.$body;
        }
        $update = TaskUpdate::query()->create([
            'organization_id' => $task->organization_id,
            'task_id' => $task->id,
            'user_id' => $author?->id,
            'body' => $body === '' ? '' : ($reply ? '↳ ' : '').$body,
        ]);
        $at = isset($u['created_at']) ? CarbonImmutable::parse($u['created_at']) : null;
        $update->forceFill(array_filter(['external_ref' => $ref, 'created_at' => $at, 'updated_at' => $at]))->saveQuietly();
        $this->counts['updates']++;
        $this->files($update, $files, $author ?? $as, false);
    }

    /**
     * Every file on the update becomes an attachment, whatever its size or
     * type (Jose, 2026-11-12: "Get rid of the Limit, we need to import
     * everything"). The upload limits (config ezbizi.attachments) stay for
     * people uploading; an import carries what Monday held. Streamed to disk,
     * never held in memory: Rodrigo's videos run to hundreds of MB. A video
     * (STREAMABLE) keeps its video type, so it plays and gets a poster; any
     * other type the upload list would refuse is stored as
     * application/octet-stream, so it downloads and is never rendered in the
     * browser (an .svg can carry script). Only a file that cannot be fetched is named in the update. An
     * update that already has a file of that name keeps it.
     */
    private function files(TaskUpdate $update, array $files, User $by, bool $existing): void
    {
        if ($files === []) {
            return;
        }
        // How many of each name the update already holds, by the file's real name: a sensitive
        // task's files read "Locked file" with the name sealed, and matching on that re-added
        // them on every run (prod, 2026-11-13). Counted, not just present: Monday allows three
        // "image.png" on one update.
        $have = [];
        foreach ($existing ? $update->attachments()->get(['original_name', 'sealed_name']) : [] as $a) {
            $real = $a->sealed_name !== null ? (string) SensitiveTasks::open($a->sealed_name) : (string) $a->original_name;
            $have[$real] = ($have[$real] ?? 0) + 1;
        }
        $extensions = array_map('mb_strtolower', (array) config('ezbizi.attachments.extensions', []));
        $notes = [];
        $carried = [];
        foreach ($files as $f) {
            $name = (string) ($f['name'] ?? 'file');
            if (($have[$name] ?? 0) > 0) {
                $have[$name]--;

                continue;
            }
            $ext = mb_strtolower(ltrim((string) ($f['ext'] ?? pathinfo($name, PATHINFO_EXTENSION)), '.'));
            $size = (int) ($f['size'] ?? 0);
            $file = null;
            $why = null;
            if (! $this->dry) {
                $file = $this->fetch($f);
                $why = $file === null ? 'could not be fetched' : null;
            } elseif ($this->filesDir && $this->local($f) === null) {
                $why = 'not in the --files folder';
            }
            if ($why !== null) {
                $line = sprintf('%s (%.1f MB, %s)', $name, $size / 1048576, $why);
                $this->notCarried[] = $line;
                if (! str_contains($update->body, '[file not carried from monday: '.$name.' (')) {
                    $notes[] = '[file not carried from monday: '.$line.']';
                }
                $this->counts['files not carried']++;

                continue;
            }
            $this->counts['files']++;
            $this->counts['files MB'] += $size / 1048576;
            $carried[] = $name;
            if ($this->dry) {
                continue;
            }
            [$path, $temporary] = $file;
            try {
                $attachment = app(AttachmentUploader::class)->upload($by, $update, new UploadedFile($path, $name, null, null, true), 'task_updates');
                if (isset(self::STREAMABLE[$ext])) {
                    $attachment->forceFill(['mime' => self::STREAMABLE[$ext]])->save();
                    if (MakeVideoPoster::wants($attachment)) {
                        MakeVideoPoster::dispatch($attachment->getKey())->afterCommit();
                    }
                } elseif (! in_array($ext, $extensions, true)) {
                    $attachment->forceFill(['mime' => 'application/octet-stream'])->save();
                }
            } finally {
                if ($temporary) {
                    @unlink($path);
                }
            }
        }
        // A note an earlier run left for a file that has now come goes.
        $body = (string) preg_replace_callback('/\n?\[file not carried from monday: ([^\]]*)\]/u',
            fn ($m) => ! $this->dry && array_filter($carried, fn ($n) => str_starts_with($m[1], $n.' (')) ? '' : $m[0], $update->body);
        $body = trim($body.($notes ? "\n".implode("\n", $notes) : ''));
        if ($body !== $update->body) {
            $update->forceFill(['body' => $body])->saveQuietly();
        }
    }

    /** The file's copy in the files folder, named <monday asset id><ext>. */
    private function local(array $f): ?string
    {
        if (! $this->filesDir || empty($f['monday_id'])) {
            return null;
        }
        $ext = ltrim((string) ($f['ext'] ?? ''), '.');
        $path = rtrim($this->filesDir, '/\\').DIRECTORY_SEPARATOR.$f['monday_id'].($ext !== '' ? '.'.$ext : '');

        return is_file($path) ? $path : null;
    }

    /** @return array{0: string, 1: bool}|null the file on disk, and whether it is a temporary copy */
    private function fetch(array $f): ?array
    {
        if ($path = $this->local($f)) {
            return [$path, false];
        }
        if (empty($f['url'])) {
            return null;
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'monday');
        try {
            $response = Http::sink($tmp)->timeout(1800)->get($f['url']);
            if ($response->successful() && filesize($tmp) > 0) {
                return [$tmp, true];
            }
        } catch (\Throwable) {
        }
        @unlink($tmp);

        return null;
    }

    /**
     * The board as Monday has it (Jose, 2026-11-13: "as we have it on
     * Monday"): its groups in Monday's order and colours, its status labels
     * with their colours and done, and its columns in Monday's order with the
     * Monday type's Task Board type and label colours. A column an earlier
     * run made as text for a typed Monday column becomes that type, its values
     * carried (ColumnConverter, the rule a person's type change meets). Empty
     * starter groups Monday does not have go, so the board shows Monday's groups.
     */
    private function layout(TaskBoard $board, array $b): void
    {
        $groups = $b['groups'] ?? [];
        foreach (array_values($groups) as $i => $g) {
            $group = $this->group($board, $g['name'], $g['colour'] ?? null);
            $group->forceFill(array_filter(['colour' => $g['colour'] ?? null, 'position' => $i + 1], fn ($v) => $v !== null))->save();
        }
        if ($groups !== []) {
            $names = array_map(fn ($g) => mb_strtolower(trim($g['name'])), $groups);
            foreach ($board->groups()->get() as $i => $group) {
                if (! in_array(mb_strtolower($group->name), $names, true)) {
                    if (in_array($group->name, array_column(TaskBoards::DEFAULT_GROUPS, 0), true) && ! Task::query()->withTrashed()->where('group_id', $group->id)->exists()) {
                        $group->delete();
                        $this->counts['starter groups removed'] = ($this->counts['starter groups removed'] ?? 0) + 1;
                    } else {
                        $group->forceFill(['position' => count($groups) + 1 + $i])->save();
                    }
                }
            }
        }

        foreach ($b['statuses'] ?? [] as $s) {
            $this->status($board, $s['label'], (bool) ($s['done'] ?? false), $s['colour'] ?? null);
        }

        foreach (array_values($b['columns'] ?? []) as $i => $c) {
            $column = $this->column($board, $c['title'], $c['type'], (array) ($c['settings'] ?? []));
            if ($column->position !== $i + 1) {
                $column->forceFill(['position' => $i + 1])->save();
            }
        }

        // Monday's named views, shared with the board, once each (by Monday's view id).
        $saved = TaskBoardView::query()->where('board_id', $board->id)->whereNull('user_id')->get();
        foreach (array_values($b['views'] ?? []) as $i => $v) {
            if ($saved->contains(fn (TaskBoardView $s) => ($s->config['monday_id'] ?? null) === $v['monday_id'])) {
                continue;
            }
            $name = $v['name'];
            for ($n = 2; $saved->contains(fn (TaskBoardView $s) => mb_strtolower($s->name) === mb_strtolower($name)); $n++) {
                $name = mb_substr($v['name'], 0, 96).' '.$n;
            }
            $saved->push(TaskBoardView::query()->create([
                'organization_id' => $board->organization_id, 'board_id' => $board->id, 'user_id' => null, 'name' => $name,
                'config' => ['match' => 'all', 'filters' => [], 'sort' => [], 'layout' => $v['layout'], 'monday_id' => $v['monday_id']],
                'position' => $i + 1,
            ]));
            $this->counts['views made'] = ($this->counts['views made'] ?? 0) + 1;
        }
    }

    /**
     * The board in Monday's folder (Jose 11-13: each board in the same folder
     * with the same colour): the folder chain is found by name under its
     * parent, or made with Monday's colour; a folder without a colour takes
     * Monday's. A board somebody here already put in a folder stays there.
     *
     * @param  list<array{name: string, colour: ?string}>|null  $path  root first; null: the export does not say
     */
    private function folder(TaskBoard $board, ?array $path): void
    {
        if ($path === null || $path === [] || $board->folder_id !== null) {
            return;
        }
        $parent = null;
        foreach ($path as $i => $f) {
            $name = mb_substr(trim((string) $f['name']), 0, 255);
            $found = TaskBoardFolder::query()->where('parent_id', $parent?->id)->get()
                ->first(fn (TaskBoardFolder $x) => mb_strtolower($x->name) === mb_strtolower($name));
            if ($found === null) {
                $found = TaskBoardFolder::query()->create([
                    'organization_id' => $board->organization_id, 'parent_id' => $parent?->id, 'name' => $name,
                    'colour' => $f['colour'] ?? null, 'position' => (int) TaskBoardFolder::query()->where('parent_id', $parent?->id)->max('position') + 1,
                ]);
                $this->counts['folders made'] = ($this->counts['folders made'] ?? 0) + 1;
            } elseif ($found->colour === null && ! empty($f['colour'])) {
                $found->forceFill(['colour' => $f['colour']])->save();
            }
            $parent = $found;
        }
        $board->forceFill(['folder_id' => $parent->id])->saveQuietly();
    }

    /**
     * Columns an existing task has no value in, filled from Monday — the
     * re-run on boards imported before a type was carried (desk, 2026-11-13:
     * "it must update existing tasks' values, not skip them"). A value
     * somebody set here is never overwritten.
     */
    private function fillBlanks(Task $task, TaskBoard $board, array $item, User $as): void
    {
        $values = $this->input($board, $item)['values'];
        $have = TaskColumnValue::query()->where('task_id', $task->id)->pluck('column_id')->all();
        $blank = array_diff_key($values, array_flip($have));
        if ($blank === []) {
            return;
        }
        $this->writer->update($task->load('board'), ['values' => $blank], $as);
        $this->quietly($task->refresh(), ['external_synced_at' => now(), 'updated_at' => now()]);
        $this->counts['values filled'] += count($blank);
    }

    private function group(TaskBoard $board, ?string $name, ?string $colour = null): TaskGroup
    {
        $name = trim((string) $name) ?: 'Open Tasks';
        $found = $board->groups()->get()->first(fn (TaskGroup $g) => mb_strtolower($g->name) === mb_strtolower($name));
        if ($found) {
            return $found;
        }
        $this->counts['groups made']++;

        return TaskGroup::query()->create([
            'organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $name,
            'colour' => $colour, 'position' => (int) $board->groups()->max('position') + 1,
        ]);
    }

    /**
     * The board's status of that name, made if missing (done when the item is
     * done, or when Monday counts the label as done), with Monday's colour
     * where it has none; a done item with no label takes the board's first
     * done status. Null keeps the board's default.
     */
    private function status(TaskBoard $board, ?string $label, bool $done, ?string $colour = null): ?string
    {
        $label = trim((string) $label);
        $statuses = $board->statuses()->orderBy('position')->get();
        if ($label === '') {
            return $done ? $statuses->firstWhere('is_done', true)?->name : null;
        }
        $found = $statuses->first(fn (TaskStatus $s) => mb_strtolower($s->name) === mb_strtolower($label));
        if ($found === null) {
            TaskStatus::query()->create([
                'organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $label,
                'is_done' => $done, 'colour' => $colour, 'position' => (int) $statuses->max('position') + 1,
            ]);
            $this->counts['statuses made']++;
        } elseif ($colour !== null && $found->colour === null) {
            $found->forceFill(['colour' => $colour])->save();
        }

        return $label;
    }

    /**
     * The board's column of that title, made with Monday's type and labels
     * when missing. An existing text column for a typed Monday column (an
     * earlier run wrote everything as text) becomes that type, its values
     * carried; a column somebody here gave another type keeps it. Monday's
     * labels are added to a status/tags column's options, and an option
     * without a colour takes Monday's.
     */
    private function column(TaskBoard $board, string $title, string $type = 'text', array $settings = []): TaskColumn
    {
        $type = in_array($type, TaskColumn::TYPES, true) ? $type : 'text';
        $found = $board->columns()->get()->first(fn (TaskColumn $c) => mb_strtolower($c->name) === mb_strtolower($title));
        if ($found === null) {
            $this->counts['columns made']++;

            return TaskColumn::query()->create([
                'organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $title,
                'type' => $type, 'settings' => $settings ?: null, 'hidden' => false,
                'position' => (int) $board->columns()->max('position') + 1,
            ]);
        }
        if ($found->type === 'text' && $type !== 'text') {
            $this->retype($found, $type, $settings);
        }
        if (in_array($found->type, TaskColumn::WITH_OPTIONS, true) && ! empty($settings['options'])) {
            $options = $found->options();
            $changed = false;
            foreach ($settings['options'] as $o) {
                $i = $this->optionIndex($options, $o['label']);
                if ($i === null) {
                    $options[] = $o;
                    $changed = true;
                } elseif (($options[$i]['colour'] ?? null) === null && ($o['colour'] ?? null) !== null) {
                    $options[$i]['colour'] = $o['colour'];
                    $changed = true;
                }
            }
            if ($changed) {
                $found->forceFill(['settings' => ['options' => $options] + ($found->settings ?? [])])->save();
            }
        }
        foreach (['unit', 'monday_formula', 'monday_type'] as $key) {
            if (isset($settings[$key]) && ! isset($found->settings[$key])) {
                $found->forceFill(['settings' => [$key => $settings[$key]] + ($found->settings ?? [])])->save();
            }
        }

        return $found;
    }

    /** A text column an earlier run made, as the Monday type it always was; values carried where they read as it. */
    private function retype(TaskColumn $column, string $type, array $settings): void
    {
        $target = $column->replicate();
        $target->type = $type;
        $target->settings = $settings ?: null;
        $plan = app(ColumnConverter::class)->plan($column, $target);
        $options = $plan['options'];
        foreach ($settings['options'] ?? [] as $o) { // Monday's colour for a label the plan made
            $i = $this->optionIndex($options, $o['label']);
            if ($i !== null && ($options[$i]['colour'] ?? null) === null) {
                $options[$i]['colour'] = $o['colour'] ?? null;
            }
        }
        foreach ($plan['rows'] as $id => $row) {
            $row === null
                ? TaskColumnValue::query()->whereKey($id)->delete()
                : TaskColumnValue::query()->whereKey($id)->update($row);
        }
        $column->forceFill([
            'type' => $type,
            'settings' => in_array($type, TaskColumn::WITH_OPTIONS, true) ? ['options' => $options] + array_diff_key($settings, ['options' => 1]) : ($settings ?: null),
        ])->save();
        $this->counts['columns retyped']++;
    }

    /** @param  list<array{id: string, label: string, colour: ?string}>  $options */
    private function optionIndex(array $options, string $label): ?int
    {
        foreach ($options as $i => $o) {
            if (mb_strtolower(trim((string) $o['label'])) === mb_strtolower(trim($label))) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The value as the column takes it (TaskWriter::encode decides), or null
     * when it cannot be carried: a label becomes the option of that label
     * (made if new), a person the first one this board can take. A column
     * somebody here kept as text gets Monday's words.
     */
    private function valueFor(TaskBoard $board, TaskColumn $column, mixed $value): mixed
    {
        $words = fn (mixed $v): string => is_array($v)
            ? (array_is_list($v) ? implode(', ', array_map('strval', $v)) : trim(($v['from'] ?? '').' – '.($v['to'] ?? ''), ' –'))
            : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v);

        $candidate = match ($column->type) {
            'status', 'dropdown' => $this->option($column, is_array($value) ? (string) ($value[0] ?? '') : (string) $value),
            'tags' => array_values(array_unique(array_filter(array_map(fn ($l) => $this->option($column, (string) $l), (array) $value)))) ?: null,
            'person' => $this->peopleFor($board, (array) $value)[0] ?? null,
            'people' => $this->peopleFor($board, (array) $value) ?: null,
            'checkbox' => is_bool($value) ? $value : in_array(mb_strtolower((string) $value), ['true', 'yes', '1', 'v'], true),
            'number', 'currency' => is_numeric($value) ? (float) $value : (is_numeric($n = preg_replace('/[$,\s]/', '', $words($value))) ? (float) $n : null),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null,
            'datetime' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value.' 00:00' : $value,
            'timeline' => is_array($value) && isset($value['from'], $value['to']) ? $value : null,
            'text' => mb_substr($words($value), 0, 5000),
            'long_text' => mb_substr($words($value), 0, 20000),
            'rating' => is_numeric($value) ? (int) $value : null,
            'location' => is_array($value) && ! empty($value['address']) ? $value : (is_string($value) && trim($value) !== '' ? ['address' => trim($value), 'lat' => null, 'lng' => null] : null),
            // Monday item ids → the tasks carrying them; one not imported yet is filled on a later run.
            'connect', 'dependency' => Task::query()->whereIn('external_ref', array_map(fn ($id) => 'monday:'.$id, array_map('strval', (array) $value)))
                ->when($column->type === 'dependency', fn ($q) => $q->where('board_id', $board->id))->pluck('id')->all() ?: null,
            'formula', 'mirror', 'creation_log', 'last_updated', 'files' => null, // computed, or written after the files arrive (fileColumns)
            default => is_string($value) ? trim($value) : null,
        };
        if ($candidate === null || $candidate === '') {
            return null;
        }
        $assignable = $this->assignable[$board->id] ?? null;
        if ($this->writer->encode($column, $candidate, $board, $assignable)[0] === null) {
            $this->counts['values not carried']++;

            return null;
        }

        return $candidate;
    }

    /** The column's option for a Monday label: matched by name, else added (no colour: Monday's arrives with the column). */
    private function option(TaskColumn $column, string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        $options = $column->options();
        $i = $this->optionIndex($options, $label);
        if ($i !== null) {
            return (string) $options[$i]['id'];
        }
        $options[] = $o = ['id' => 'monday-'.substr(md5(mb_strtolower($label)), 0, 10), 'label' => mb_substr($label, 0, 100), 'colour' => null];
        $column->forceFill(['settings' => ['options' => $options] + ($column->settings ?? [])])->save();

        return $o['id'];
    }

    /**
     * The Monday people this board can name, as user ids (a People column
     * holds them all; an old single Person column the first). On a custom
     * board somebody named on Monday's board joins it, as an assignee does.
     *
     * @return list<string>
     */
    private function peopleFor(TaskBoard $board, array $emails): array
    {
        $this->assignable[$board->id] ??= $this->boards->assignable($board)->pluck('id')->all();
        $out = [];
        foreach ($emails as $email) {
            $user = $this->person((string) $email);
            if ($user !== null && $user->is_active && $board->kind === TaskBoard::KIND_CUSTOM && ! in_array($user->id, $this->assignable[$board->id], true)) {
                $this->members($board, [['email' => $user->email, 'role' => 'member']]);
                $this->assignable[$board->id] = $this->boards->assignable($board)->pluck('id')->all();
            }
            if ($user !== null && in_array($user->id, $this->assignable[$board->id], true)) {
                $out[] = $user->id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The EzBizi person behind a monday email: the people map, the same
     * email, then a name that names one person only — exactly, or with every
     * word of the monday name in theirs ("Jose Demasi" is "Jose Luis Demasi").
     */
    private function person(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }
        $key = mb_strtolower(trim($email));
        $user = $this->people[$this->map[$key] ?? $key] ?? $this->people[$key] ?? null;
        if ($user === null && isset($this->mondayNames[$key])) {
            $words = fn (string $n) => array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($n))) ?: []));
            $name = $words($this->mondayNames[$key]);
            $same = array_values(array_filter($this->people, fn (User $u) => $words((string) $u->name) === $name));
            if ($same === [] && count($name) > 1) {
                $same = array_values(array_filter($this->people, fn (User $u) => array_diff($name, $words((string) $u->name)) === []));
            }
            $user = count($same) === 1 ? $same[0] : null;
        }
        if ($user === null) {
            $this->unmatched[$key] = true;
        }

        return $user;
    }
}
