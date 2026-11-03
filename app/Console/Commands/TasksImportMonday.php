<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings A-1's open monday.com items onto the Task Board (Jose, 2026-11-03:
 * "Replace monday until Nov 10th"). Reads a JSON export (shape: the export
 * section of docs/reviews/2026-11-03/monday-survey.md), never Monday itself.
 *
 * A personal board (owner_email) lands on that person's own board; any other
 * becomes a main board of the same name. Groups, statuses and text columns
 * are made by name where missing. Subitems become subtasks, updates and their
 * replies become updates, with Monday's dates. People are matched by email.
 *
 * Idempotent: every board, task and update carries external_ref
 * "monday:<id>" and a re-run skips what it already brought. No automation
 * runs for imported tasks. --dry-run rolls everything back.
 */
class TasksImportMonday extends Command
{
    protected $signature = 'ezbizi:tasks-import-monday {file} {--org=} {--as=} {--dry-run}';

    protected $description = 'Import open monday.com items from a JSON export onto the Task Board';

    /** @var array<string, User> lower-cased email => user */
    private array $people = [];

    private array $counts = ['boards made' => 0, 'tasks' => 0, 'subtasks' => 0, 'updates' => 0, 'skipped (already imported)' => 0, 'statuses made' => 0, 'groups made' => 0, 'columns made' => 0];

    /** @var array<string, true> */
    private array $unmatched = [];

    private array $ownersDropped = [];

    public function handle(TaskBoards $boards, TenantContext $tenant): int
    {
        $data = json_decode((string) file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($this->option('org'));
        $dry = (bool) $this->option('dry-run');

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
                        $parent = $this->task($board, $item, null, $writer, $as);
                        foreach ($item['subitems'] ?? [] as $sub) {
                            $this->task($board, $sub + ['group' => $item['group']], $parent, $writer, $as);
                        }
                    }
                }
            });
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->line($dry ? '[dry run, nothing saved]' : '[saved]');
        foreach ($this->counts as $k => $v) {
            $this->line("$k: $v");
        }
        $this->line('people not matched: '.count($this->unmatched).($this->unmatched ? ' ('.implode(', ', array_keys($this->unmatched)).')' : ''));
        $this->line('owners dropped (not on that board): '.count($this->ownersDropped).($this->ownersDropped ? ' ('.implode(', ', $this->ownersDropped).')' : ''));

        return self::SUCCESS;
    }

    private function board(array $b, TaskBoards $boards, User $as): TaskBoard
    {
        $ref = 'monday:'.$b['monday_id'];
        if ($owner = $this->person($b['owner_email'] ?? null)) {
            return $boards->ensureFor($owner);
        }
        $board = TaskBoard::query()->where('external_ref', $ref)->first();
        if ($board === null) {
            $board = $boards->createCustom($as, $b['name'], TaskBoard::MAIN, null, null);
            $board->forceFill(['external_ref' => $ref])->saveQuietly();
            $this->counts['boards made']++;
        }

        return $board;
    }

    private function task(TaskBoard $board, array $item, ?Task $parent, TaskWriter $writer, User $as): ?Task
    {
        $ref = 'monday:'.$item['monday_id'];
        if ($done = Task::query()->where('external_ref', $ref)->first()) {
            $this->counts['skipped (already imported)']++;

            return $done;
        }

        // Ohio's board has no Status column; its Job Status is the status.
        $columns = $item['columns'] ?? [];
        if (empty($item['status']) && ! empty($columns['Job Status'])) {
            $item['status'] = $columns['Job Status'];
            unset($columns['Job Status']);
        }
        $values = [];
        foreach ($columns as $title => $text) {
            if ($text !== null && $text !== '') {
                $this->column($board, $title);
                $values[$title] = (string) $text;
            }
        }
        $due = $item['due_on'] ?? null;
        if (! empty($item['timeline'])) {
            $due ??= $item['timeline']['to'] ?? null;
            $this->column($board, 'Timeline');
            $values['Timeline'] = trim(($item['timeline']['from'] ?? '').' – '.($item['timeline']['to'] ?? ''), ' –');
        }
        $owners = array_values(array_filter(array_map(fn ($e) => $this->person($e)?->id, $item['people'] ?? [])));

        $input = [
            'title' => mb_substr(trim($item['name']) ?: '(untitled)', 0, 255),
            'parent_task_id' => $parent?->id,
            'group' => $this->group($board, $item['group'])->name,
            'status' => $this->status($board, $item['status'] ?? null),
            'priority' => isset($item['priority']) ? mb_substr($item['priority'], 0, 16) : null,
            'due_on' => $due,
            'values' => $values,
        ] + ($owners ? ['assignee_user_ids' => $owners] : []);

        try {
            $task = $writer->create($board, $input, $as);
        } catch (ValidationException) {
            // An owner this board cannot take: the task still comes, unowned.
            $this->ownersDropped[] = $ref;
            unset($input['assignee_user_ids']);
            $task = $writer->create($board, $input, $as);
        }
        $created = isset($item['created_at']) ? CarbonImmutable::parse($item['created_at']) : null;
        $task->forceFill(array_filter([
            'external_ref' => $ref,
            'created_by_user_id' => $this->person($item['creator_email'] ?? null)?->id ?? $as->id,
            'created_at' => $created,
        ]))->saveQuietly();
        $this->counts[$parent ? 'subtasks' : 'tasks']++;

        foreach ($item['updates'] ?? [] as $u) {
            $this->update($task, $u, 'monday:'.$u['monday_id']);
            foreach ($u['replies'] ?? [] as $i => $r) {
                $this->update($task, $r, 'monday:'.$u['monday_id'].'.'.$i, true);
            }
        }

        return $task;
    }

    private function update(Task $task, array $u, string $ref, bool $reply = false): void
    {
        $body = trim((string) ($u['body'] ?? ''));
        if ($body === '' || TaskUpdate::query()->where('external_ref', $ref)->exists()) {
            return;
        }
        $author = $this->person($u['author_email'] ?? null);
        if ($author === null && ! empty($u['author_email'])) {
            $body = $u['author_email'].' (on Monday): '.$body;
        }
        $update = TaskUpdate::query()->create([
            'organization_id' => $task->organization_id,
            'task_id' => $task->id,
            'user_id' => $author?->id,
            'body' => ($reply ? '↳ ' : '').$body,
        ]);
        $at = isset($u['created_at']) ? CarbonImmutable::parse($u['created_at']) : null;
        $update->forceFill(array_filter(['external_ref' => $ref, 'created_at' => $at, 'updated_at' => $at]))->saveQuietly();
        $this->counts['updates']++;
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

    /** The board's status of that name, made if missing; null keeps the board's default. */
    private function status(TaskBoard $board, ?string $label): ?string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }
        if (! $board->statuses()->get()->contains(fn (TaskStatus $s) => mb_strtolower($s->name) === mb_strtolower($label))) {
            TaskStatus::query()->create([
                'organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $label,
                'is_done' => false, 'position' => (int) $board->statuses()->max('position') + 1,
            ]);
            $this->counts['statuses made']++;
        }

        return $label;
    }

    private function column(TaskBoard $board, string $title): void
    {
        if ($board->columns()->get()->contains(fn (TaskColumn $c) => mb_strtolower($c->name) === mb_strtolower($title))) {
            return;
        }
        TaskColumn::query()->create([
            'organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $title,
            'type' => 'text', 'hidden' => false, 'position' => (int) $board->columns()->max('position') + 1,
        ]);
        $this->counts['columns made']++;
    }

    private function person(?string $email): ?User
    {
        if ($email === null || $email === '') {
            return null;
        }
        $user = $this->people[mb_strtolower(trim($email))] ?? null;
        if ($user === null) {
            $this->unmatched[mb_strtolower(trim($email))] = true;
        }

        return $user;
    }
}
