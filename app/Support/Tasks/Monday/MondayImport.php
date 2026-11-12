<?php

namespace App\Support\Tasks\Monday;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Files\AttachmentUploader;
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
    public function run(Organization $org, array $data, string|User $as, bool $dry = false, ?string $filesDir = null, bool $refresh = false): array
    {
        $this->reset($data, $dry, $filesDir, $refresh);

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
            $this->tenant->run($org, function () use ($data, $as) {
                $this->people = User::query()->get()->keyBy(fn (User $u) => mb_strtolower($u->email))->all();
                $actor = $as instanceof User ? $as : ($this->person($as)
                    ?? throw new \RuntimeException('--as must be the email of a user in this organization.'));
                foreach ($data['boards'] as $b) {
                    $board = $this->board($b, $actor);
                    foreach ($b['groups'] ?? [] as $g) {
                        $this->group($board, $g['name'], $g['colour'] ?? null);
                    }
                    foreach ($b['items'] as $item) {
                        $parent = $this->task($board, $item, null, $actor);
                        foreach ($item['subitems'] ?? [] as $sub) {
                            $this->task($board, $sub + ['group' => $item['group']], $parent, $actor);
                        }
                    }
                }
            });
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
            'assignees kept as text' => 0,
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
            $board = $this->boards->createCustom($as, $b['name'], TaskBoard::MAIN, null, null);
            $board->forceFill(['external_ref' => $ref])->saveQuietly();
            $this->counts['boards made']++;
        }

        return $board;
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
            $this->owners($board, $item['people'] ?? []);
            $this->assignedInMonday($existing, $as);
            $this->updates($existing, $item, $as);

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

        return $task;
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
            $item['status'] = $columns['Job Status'];
            unset($columns['Job Status']);
        }
        $done = (bool) ($item['done'] ?? false)
            || (trim((string) ($item['status'] ?? '')) === '' && in_array(mb_strtolower(trim((string) $item['group'])), self::DONE_GROUPS, true));

        $values = [];
        foreach ($columns as $title => $value) {
            [$type, $value] = is_array($value) ? [$value['type'] ?? 'text', $value['value'] ?? null] : ['text', $value];
            if ($value !== null && $value !== '') {
                $values[$this->column($board, $title, $type)->name] = $this->valueFor($board, $title, $value);
            }
        }
        $due = $item['due_on'] ?? null;
        if (! empty($item['timeline']['from']) && ! empty($item['timeline']['to'])) {
            $due ??= $item['timeline']['to'];
            $values[$this->column($board, 'Timeline', 'timeline')->name] = $this->valueFor($board, 'Timeline', ['from' => $item['timeline']['from'], 'to' => $item['timeline']['to']]);
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
     * never held in memory: Rodrigo's videos run to hundreds of MB. A type
     * the upload list would refuse is stored as application/octet-stream, so
     * it downloads and is never rendered in the browser (an .svg can carry
     * script). Only a file that cannot be fetched is named in the update. An
     * update that already has a file of that name keeps it.
     */
    private function files(TaskUpdate $update, array $files, User $by, bool $existing): void
    {
        if ($files === []) {
            return;
        }
        $have = $existing ? $update->attachments()->pluck('original_name')->all() : [];
        $extensions = array_map('mb_strtolower', (array) config('ezbizi.attachments.extensions', []));
        $notes = [];
        $carried = [];
        foreach ($files as $f) {
            $name = (string) ($f['name'] ?? 'file');
            if (in_array($name, $have, true)) {
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
                if (! in_array($ext, $extensions, true)) {
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
     * done); a done item with no label takes the board's first done status.
     * Null keeps the board's default.
     */
    private function status(TaskBoard $board, ?string $label, bool $done): ?string
    {
        $label = trim((string) $label);
        $statuses = $board->statuses()->orderBy('position')->get();
        if ($label === '') {
            return $done ? $statuses->firstWhere('is_done', true)?->name : null;
        }
        if (! $statuses->contains(fn (TaskStatus $s) => mb_strtolower($s->name) === mb_strtolower($label))) {
            TaskStatus::query()->create([
                'organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $label,
                'is_done' => $done, 'position' => (int) $statuses->max('position') + 1,
            ]);
            $this->counts['statuses made']++;
        }

        return $label;
    }

    private function column(TaskBoard $board, string $title, string $type = 'text'): TaskColumn
    {
        $found = $board->columns()->get()->first(fn (TaskColumn $c) => mb_strtolower($c->name) === mb_strtolower($title));
        if ($found) {
            return $found;
        }
        $this->counts['columns made']++;

        return TaskColumn::query()->create([
            'organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $title,
            'type' => in_array($type, TaskColumn::TYPES, true) ? $type : 'text', 'hidden' => false,
            'position' => (int) $board->columns()->max('position') + 1,
        ]);
    }

    /** The value as that column takes it; an existing column of another type gets the text. */
    private function valueFor(TaskBoard $board, string $title, mixed $value): mixed
    {
        $column = $this->column($board, $title);
        $text = is_array($value) ? trim(($value['from'] ?? '').' – '.($value['to'] ?? ''), ' –') : (string) $value;

        return match ($column->type) {
            'timeline' => is_array($value) ? $value : null,
            'number', 'currency' => is_numeric($value) ? (float) $value : null,
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null,
            'text', 'long_text' => mb_substr($text, 0, 5000),
            default => null,
        };
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
