<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
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
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Brings A-1's monday.com boards onto the Task Board (Jose, 2026-11-03:
 * "Replace monday until Nov 10th"; 2026-11-12: Jose, Rodrigo, Ana, Brian,
 * Jonathan with everything). Reads a JSON export, never Monday itself. Shape:
 * docs/agents/monday-sync-design-2026-11-12.md ("Export shape").
 *
 * A personal board (owner_email) lands on that person's own board; any other
 * becomes a main board of the same name. Groups, statuses and columns are made
 * by name where missing. Subitems become subtasks, updates and their replies
 * become updates with Monday's dates, and their files become attachments.
 * People are matched through the export's `people` map (monday email =>
 * EzBizi email), then by email, then by an unambiguous name.
 *
 * Done: an item marked `done` lands on a done status (made done when new)
 * with Monday's completion time.
 *
 * Idempotent: every board, task and update carries external_ref
 * "monday:<id>"; a re-run skips what it already brought. A task deleted here
 * (the 11-03 run, deleted 11-09) is restored, moved to the target board and
 * refreshed instead of colliding on the unique key. No automation runs for
 * imported tasks. --dry-run rolls everything back and fetches no file.
 */
class TasksImportMonday extends Command
{
    protected $signature = 'ezbizi:tasks-import-monday {file} {--org=} {--as=} {--files= : folder holding <monday asset id><ext>, tried before the export\'s url} {--dry-run}';

    protected $description = 'Import monday.com boards from a JSON export onto the Task Board';

    /** Group names whose items are done even with a blank status. */
    private const DONE_GROUPS = ['done', 'completed', 'completed tasks', 'completed hirings', 'ezbizi - done'];

    /** @var array<string, User> lower-cased email => user */
    private array $people = [];

    /** @var array<string, string> lower-cased monday email => lower-cased EzBizi email */
    private array $map = [];

    /** @var array<string, string> lower-cased monday email => monday name */
    private array $mondayNames = [];

    private array $counts = [
        'boards made' => 0, 'tasks open' => 0, 'tasks done' => 0, 'subtasks open' => 0, 'subtasks done' => 0,
        'restored (deleted earlier)' => 0, 'skipped (already imported)' => 0, 'updates' => 0, 'updates already there' => 0, 'files' => 0,
        'files MB' => 0.0, 'files not carried' => 0, 'statuses made' => 0, 'groups made' => 0, 'columns made' => 0,
    ];

    /** @var array<string, true> */
    private array $unmatched = [];

    /** @var array<string, int> name => tasks they were dropped from */
    private array $ownersDropped = [];

    /** @var list<string> */
    private array $notCarried = [];

    private bool $dry = false;

    /** @var array<string, list<string>> board id => user ids it can take as owners */
    private array $assignable = [];

    public function handle(TaskBoards $boards, TenantContext $tenant): int
    {
        $data = json_decode((string) file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($this->option('org'));
        $this->dry = (bool) $this->option('dry-run');
        foreach ($data['people'] ?? [] as $from => $to) {
            $this->map[mb_strtolower(trim($from))] = mb_strtolower(trim($to));
        }
        foreach ($data['users'] ?? [] as $u) {
            if (! empty($u['email']) && ! empty($u['name'])) {
                $this->mondayNames[mb_strtolower(trim($u['email']))] = $u['name'];
            }
        }

        // Imports are history, not events: no rule texts anyone about them.
        app()->instance(EventEmitter::class, new class extends EventEmitter
        {
            public function __construct() {}

            public function emitAfterCommit(string $eventKey, ?Model $subject = null, array $payload = []): void {}
        });
        $writer = app(TaskWriter::class);

        DB::beginTransaction();
        try {
            $tenant->run($org, function () use ($data, $boards, $writer) {
                $this->people = User::query()->get()->keyBy(fn (User $u) => mb_strtolower($u->email))->all();
                $as = $this->person($this->option('as')) ?? throw new \RuntimeException('--as must be the email of a user in this organization.');
                foreach ($data['boards'] as $b) {
                    $board = $this->board($b, $boards, $as);
                    foreach ($b['groups'] ?? [] as $g) {
                        $this->group($board, $g['name'], $g['colour'] ?? null);
                    }
                    foreach ($b['items'] as $item) {
                        $parent = $this->task($board, $item, null, $writer, $boards, $as);
                        foreach ($item['subitems'] ?? [] as $sub) {
                            $this->task($board, $sub + ['group' => $item['group']], $parent, $writer, $boards, $as);
                        }
                    }
                }
            });
            $this->dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->line($this->dry ? '[dry run, nothing saved, no file fetched]' : '[saved]');
        $this->counts['files MB'] = round($this->counts['files MB'], 1);
        foreach ($this->counts as $k => $v) {
            $this->line("$k: $v");
        }
        $this->line('people not matched: '.count($this->unmatched).($this->unmatched ? ' ('.implode(', ', array_keys($this->unmatched)).')' : ''));
        $this->line('owners dropped (cannot own a task on that board): '.array_sum($this->ownersDropped)
            .($this->ownersDropped ? ' ('.implode(', ', array_map(fn ($n, $c) => "$n x$c", array_keys($this->ownersDropped), $this->ownersDropped)).')' : ''));
        foreach ($this->notCarried as $line) {
            $this->line('  file not carried: '.$line);
        }

        return self::SUCCESS;
    }

    private function board(array $b, TaskBoards $boards, User $as): TaskBoard
    {
        $ref = 'monday:'.$b['monday_id'];
        if ($owner = $this->person($b['owner_email'] ?? null)) {
            return $boards->ensureFor($owner);
        }
        $board = TaskBoard::query()->withTrashed()->where('external_ref', $ref)->first();
        if ($board?->trashed()) {
            $board->restore();
        }
        if ($board === null) {
            $board = $boards->createCustom($as, $b['name'], TaskBoard::MAIN, null, null);
            $board->forceFill(['external_ref' => $ref])->saveQuietly();
            $this->counts['boards made']++;
        }

        return $board;
    }

    private function task(TaskBoard $board, array $item, ?Task $parent, TaskWriter $writer, TaskBoards $boards, User $as): ?Task
    {
        $ref = 'monday:'.$item['monday_id'];
        $existing = Task::query()->withTrashed()->where('external_ref', $ref)->first();
        if ($existing !== null && ! $existing->trashed()) {
            $this->counts['skipped (already imported)']++;
            $this->updates($existing, $item, $as);

            return $existing;
        }

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

        $input = [
            'title' => mb_substr(trim($item['name']) ?: '(untitled)', 0, 255),
            'group' => $this->group($board, $item['group'])->name,
            'status' => $this->status($board, $item['status'] ?? null, $done),
            'priority' => isset($item['priority']) ? mb_substr($item['priority'], 0, 16) : null,
            'due_on' => $due,
            'values' => array_filter($values, fn ($v) => $v !== null),
            'assignee_user_ids' => $this->owners($board, $boards, $item['people'] ?? []),
        ];

        if ($existing !== null) {
            $task = $this->reuse($existing, $board, $parent, $input, $writer, $as);
        } else {
            $task = $writer->create($board, $input + ['parent_task_id' => $parent?->id], $as);
        }

        $created = isset($item['created_at']) ? CarbonImmutable::parse($item['created_at']) : null;
        $isDone = (bool) $task->statusRow?->is_done;
        $task->forceFill(array_filter([
            'external_ref' => $ref,
            'created_by_user_id' => $this->person($item['creator_email'] ?? null)?->id ?? $as->id,
            'created_at' => $created,
        ]) + ['completed_at' => $isDone ? (isset($item['completed_at']) ? CarbonImmutable::parse($item['completed_at']) : $task->completed_at) : null])->saveQuietly();
        $this->counts[($parent ? 'subtasks' : 'tasks').($isDone ? ' done' : ' open')]++;

        $this->updates($task, $item, $as);

        return $task;
    }

    /**
     * The 11-03 run's row for this monday item, deleted on 11-09: bring it
     * back onto $board and refresh it, so the unique key is reused, not hit.
     */
    private function reuse(Task $task, TaskBoard $board, ?Task $parent, array $input, TaskWriter $writer, User $as): Task
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
            $writer->move($task, $this->group($board, $input['group']), null, $as);
        }
        // Values on the old board's columns mean nothing here.
        TaskColumnValue::query()->where('task_id', $task->id)
            ->whereNotIn('column_id', TaskColumn::query()->where('board_id', $task->board_id)->select('id'))->delete();
        // A status from the old board would not resolve; the import's status (or the board default) replaces it.
        $input['status'] ??= $board->statuses()->where('key', Task::STATUS_NOT_STARTED)->value('name') ?? $board->statuses()->value('name');
        $task->load('board');

        return $writer->update($task, $input, $as)->refresh();
    }

    /** Owners this board can take; the others are reported, never silently lost. */
    private function owners(TaskBoard $board, TaskBoards $boards, array $emails): array
    {
        $this->assignable[$board->id] ??= $boards->assignable($board)->pluck('id')->all();
        $out = [];
        foreach ($emails as $email) {
            $user = $this->person($email);
            if ($user === null) {
                continue;
            }
            if (in_array($user->id, $this->assignable[$board->id], true)) {
                $out[] = $user->id;
            } else {
                $this->ownersDropped[$user->name] = ($this->ownersDropped[$user->name] ?? 0) + 1;
            }
        }

        return array_values(array_unique($out));
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
     * Each file on the update becomes an attachment, within the house limits
     * (config ezbizi.attachments). What does not fit is named in the update
     * and in the report. An update that already has a file of that name keeps it.
     */
    private function files(TaskUpdate $update, array $files, User $by, bool $existing): void
    {
        if ($files === []) {
            return;
        }
        $have = $existing ? $update->attachments()->pluck('original_name')->all() : [];
        $extensions = array_map('mb_strtolower', (array) config('ezbizi.attachments.extensions', []));
        $max = (int) config('ezbizi.attachments.max_kilobytes') * 1024;
        $notes = [];
        foreach ($files as $f) {
            $name = (string) ($f['name'] ?? 'file');
            if (in_array($name, $have, true)) {
                continue;
            }
            $ext = mb_strtolower(ltrim((string) ($f['ext'] ?? pathinfo($name, PATHINFO_EXTENSION)), '.'));
            $size = (int) ($f['size'] ?? 0);
            $why = match (true) {
                ! in_array($ext, $extensions, true) => "type .$ext not accepted",
                $size > $max => 'over '.round($max / 1048576).' MB',
                default => null,
            };
            $bytes = null;
            if ($why === null && ! $this->dry) {
                $bytes = $this->fetch($f);
                $why = $bytes === null ? 'could not be fetched' : null;
            } elseif ($why === null && $this->option('files') && $this->local($f) === null) {
                $why = 'not in the --files folder';
            }
            if ($why !== null) {
                $line = sprintf('%s (%.1f MB, %s)', $name, $size / 1048576, $why);
                $this->notCarried[] = $line;
                $notes[] = '[file not carried from monday: '.$line.']';
                $this->counts['files not carried']++;

                continue;
            }
            $this->counts['files']++;
            $this->counts['files MB'] += $size / 1048576;
            if ($this->dry) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'monday');
            file_put_contents($tmp, $bytes);
            try {
                app(AttachmentUploader::class)->upload($by, $update, new UploadedFile($tmp, $name, null, null, true), 'task_updates');
            } finally {
                @unlink($tmp);
            }
        }
        if ($notes !== []) {
            $update->forceFill(['body' => trim($update->body."\n".implode("\n", $notes))])->saveQuietly();
        }
    }

    /** The file's copy in the --files folder, named <monday asset id><ext>. */
    private function local(array $f): ?string
    {
        $dir = $this->option('files');
        if (! $dir || empty($f['monday_id'])) {
            return null;
        }
        $ext = ltrim((string) ($f['ext'] ?? ''), '.');
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$f['monday_id'].($ext !== '' ? '.'.$ext : '');

        return is_file($path) ? $path : null;
    }

    private function fetch(array $f): ?string
    {
        if ($path = $this->local($f)) {
            return (string) file_get_contents($path);
        }
        if (! empty($f['url'])) {
            try {
                $response = Http::timeout(120)->get($f['url']);

                return $response->successful() ? $response->body() : null;
            } catch (\Throwable) {
                return null;
            }
        }

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

    private function person(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }
        $key = mb_strtolower(trim($email));
        $user = $this->people[$this->map[$key] ?? $key] ?? $this->people[$key] ?? null;
        if ($user === null && isset($this->mondayNames[$key])) {
            $name = mb_strtolower(trim($this->mondayNames[$key]));
            $same = array_values(array_filter($this->people, fn (User $u) => mb_strtolower(trim((string) $u->name)) === $name));
            $user = count($same) === 1 ? $same[0] : null;
        }
        if ($user === null) {
            $this->unmatched[$key] = true;
        }

        return $user;
    }
}
