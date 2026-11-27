<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A board a module owns and mirrors its records onto (Customer Experience 11-20: every complaint
 * on a "Reviews & Complaints" board). The module keeps the truth; the board is a working view.
 *
 *   board('cx-complaints', 'Reviews & Complaints', statuses, columns)   once per tenant, idempotent
 *   upsert($board, 'complaint:<id>', [title, group, status, owners, due_on, values])   create or update
 *   note($task, $author, $text)                                          the record's note, as an update
 *   ModuleBoard::listen('complaint:', fn (Task $t, array $kinds, ?TaskUpdate $u) => ...)   a person's edit
 *
 * A change made through this class never calls the listener back (no echo between the two sides).
 */
final class ModuleBoard
{
    /** @var array<string, callable> ref prefix => listener */
    private static array $listeners = [];

    private static int $writing = 0;

    public static function listen(string $refPrefix, callable $listener): void
    {
        self::$listeners[$refPrefix] = $listener;
    }

    /** From TaskWriter: a task changed (or got an update) through anything but this class. */
    public static function changed(Task $task, array $kinds, ?TaskUpdate $update = null): void
    {
        if (self::$writing > 0 || $task->external_ref === null) {
            return;
        }
        foreach (self::$listeners as $prefix => $listener) {
            if (str_starts_with((string) $task->external_ref, $prefix)) {
                DB::afterCommit(fn () => $listener($task->fresh() ?? $task, $kinds, $update));
            }
        }
    }

    /**
     * The module's board in the current organization, made the first time; its statuses and columns are added where missing.
     *
     * @param  array<string, bool>  $statuses  name => is_done
     * @param  array<string, string|list<string>>  $columns  name => type ('text', 'date', 'number', 'person') or a list of labels (a status column)
     */
    public function board(string $key, string $name, array $statuses = [], array $columns = []): TaskBoard
    {
        $ref = 'module:'.$key;
        $board = TaskBoard::query()->where('external_ref', $ref)->first() ?? DB::transaction(fn () => tap(TaskBoard::query()->create([
            'organization_id' => app(TenantContext::class)->idOrFail(),
            'kind' => TaskBoard::KIND_CUSTOM,
            'name' => $name,
            'visibility' => TaskBoard::MAIN,
        ]), fn (TaskBoard $b) => $b->forceFill(['external_ref' => $ref])->save()));
        foreach (array_values(array_keys($statuses)) as $i => $label) {
            TaskStatus::query()->firstOrCreate(['board_id' => $board->id, 'name' => $label], [
                'organization_id' => $board->organization_id, 'key' => Str::slug($label, '_'), 'position' => $i, 'is_done' => $statuses[$label],
            ]);
        }
        $pos = (int) TaskColumn::query()->where('board_id', $board->id)->max('position');
        foreach ($columns as $col => $type) {
            TaskColumn::query()->firstOrCreate(['board_id' => $board->id, 'name' => $col], [
                'organization_id' => $board->organization_id, 'position' => ++$pos,
                'type' => is_array($type) ? 'status' : $type,
                'settings' => is_array($type) ? ['options' => array_map(fn ($l) => ['id' => Str::slug($l, '_'), 'label' => $l, 'colour' => null], $type)] : null,
            ]);
        }

        return $board;
    }

    /**
     * Create or update the card for $ref. Values are by column name: text as is, a status column by its label.
     *
     * @param  array{title?: string, group?: string, status?: string, owners?: list<string>, due_on?: ?string, values?: array<string, mixed>}  $fields
     */
    public function upsert(TaskBoard $board, string $ref, array $fields, ?User $actor = null): Task
    {
        $input = array_intersect_key($fields, array_flip(['title', 'due_on']));
        if (isset($fields['group'])) {
            $input['group_id'] = TaskGroup::query()->firstOrCreate(['board_id' => $board->id, 'name' => $fields['group']], [
                'organization_id' => $board->organization_id, 'position' => (int) TaskGroup::query()->where('board_id', $board->id)->max('position') + 1,
            ])->id;
        }
        if (isset($fields['status'])) {
            $input['status_id'] = TaskStatus::query()->where('board_id', $board->id)->where('name', $fields['status'])->value('id');
        }
        if (array_key_exists('owners', $fields)) {
            $input['assignee_user_ids'] = array_values(array_map('strval', $fields['owners']));
        }
        $columns = TaskColumn::query()->where('board_id', $board->id)->get()->keyBy('name');
        foreach ((array) ($fields['values'] ?? []) as $name => $value) {
            $column = $columns[$name] ?? null;
            if ($column === null) {
                continue;
            }
            if ($column->type === 'status' && $value !== null) {
                $value = collect($column->options())->first(fn ($o) => mb_strtolower($o['label']) === mb_strtolower((string) $value))['id'] ?? null;
            }
            $input['values'][$column->id] = $value;
        }

        self::$writing++;
        try {
            $writer = app(TaskWriter::class);
            $task = Task::query()->where('board_id', $board->id)->where('external_ref', $ref)->first();
            if ($task !== null) {
                return $writer->update($task, $input, $actor);
            }
            $task = $writer->create($board, $input + ['title' => $fields['title'] ?? $ref, 'source' => 'module'], $actor);
            $task->forceFill(['external_ref' => $ref])->saveQuietly();

            return $task;
        } finally {
            self::$writing--;
        }
    }

    /** The record's note on its card, as an update. */
    public function note(Task $task, User $author, string $text): TaskUpdate
    {
        self::$writing++;
        try {
            return app(TaskWriter::class)->comment($task, $author, $text);
        } finally {
            self::$writing--;
        }
    }
}
