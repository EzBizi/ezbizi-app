<?php

namespace App\Support\Tasks\Monday;

use App\Http\Controllers\Api\TasksController;
use App\Models\MondayOutboxEntry;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\User;
use App\Support\Tasks\ColumnConverter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Task Board → Monday, the sending half (Jose, 2026-11-13: two-way while both
 * are in use; writes authorized). Each pending MondayOutbox row names fields
 * of a task; the task is read as it is now and its Monday item is set to
 * match: name, status, due date, assignees, its own columns, its group, a
 * new item or subitem, a new update.
 *
 * - One sender per connection, under the token's own lock
 *   (monday-token:<connection>), so a refresh never rotates the token in the
 *   middle of a batch; the token is made fresh before the lock is taken.
 * - Last writer wins, by timestamp: when Monday's item changed after the
 *   change here, Monday's is kept and the change here is the loser; when the
 *   change here is later, it is sent and Monday's earlier one is the loser.
 *   Either way the loser is written to the task's Activity.
 * - Idempotent: a new item's id is stored on the task before anything else,
 *   an update's id on the update; after a send the item's new updated_at is
 *   taken as already seen, so the next read does not bring the echo back.
 * - A sensitive task is never sent (its words are sealed here).
 */
final class MondayPush
{
    public const MAX_ATTEMPTS = 8;

    /** @var array<string, array> monday board id => writeMeta */
    private array $meta = [];

    /** @var array<string, string>|null EzBizi user id => monday user id */
    private ?array $mondayIds = null;

    /** @var array<string, string> monday email => EzBizi email (the connection's people map) */
    private array $map = [];

    private MondayBoardReader $reader;

    private MondayWriter $writer;

    /** Set by `ezbizi:monday-push --pause[=minutes]`, cleared by `--resume`: every sender checks it. */
    public const PAUSED = 'monday-push:paused';

    /** @var array<string, ?array> lastWriter's read of an item, reused by send() */
    private array $seen = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string, int> counts */
    /**
     * @param  int  $shard  with $shards > 1, only the tasks whose CRC32(task_id) % $shards is
     *                      $shard: senders in parallel on disjoint tasks, each task's rows in order
     */
    public function run(SocialConnection $connection, int $limit = 50, ?MondayClient $client = null, ?MondayWriter $writer = null, int $shard = 0, int $shards = 1): array
    {
        $token = app(MondayToken::class)->for($connection);
        $this->reader = new MondayBoardReader($client ?? new MondayClient($token));
        $this->writer = $writer ?? new MondayWriter($token);
        $this->meta = [];
        $this->mondayIds = null;
        $this->map = [];
        foreach ((array) ($connection->setup['monday']['people'] ?? []) as $from => $to) {
            $this->map[mb_strtolower(trim((string) $from))] = mb_strtolower(trim((string) $to));
        }
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
        $counts = ['sent' => 0, 'superseded' => 0, 'deferred' => 0, 'failed' => 0];
        if (Cache::has(self::PAUSED)) {
            return $counts + ['paused' => 1]; // ezbizi:monday-push --pause; rows wait in the outbox
        }

        try {
            // One sender per shard; the token's own lock (MondayToken) guards only its refresh.
            return Cache::lock('monday-push:'.$connection->getKey().':'.$shard.'/'.$shards, 300)->block(5, fn () => $this->tenant->run($org, function () use ($connection, $limit, $shard, $shards, &$counts) {
                $entries = MondayOutboxEntry::query()->where('state', MondayOutboxEntry::PENDING)
                    ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                    ->when($shards > 1, fn ($q) => $q->whereRaw('MOD(CRC32(task_id), ?) = ?', [$shards, $shard]))
                    ->orderBy('changed_at')->limit($limit)->get();
                foreach ($entries as $entry) {
                    try {
                        $outcome = $this->send($entry, $connection);
                    } catch (MondayRefused $e) {
                        throw $e; // the connection is broken: stop, retry next run
                    } catch (\Throwable $e) {
                        if (str_contains($e->getMessage(), 'USER_UNAUTHORIZED') || str_contains($e->getMessage(), 'inactive item')) {
                            // Monday's 403 on one item does not change on a retry (desk 11-18: an archived item).
                            $counts[$this->refused($entry, $e)]++;

                            continue;
                        }
                        $attempts = $entry->attempts + 1;
                        $entry->forceFill([
                            'attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 500),
                            'state' => $attempts >= self::MAX_ATTEMPTS ? MondayOutboxEntry::FAILED : MondayOutboxEntry::PENDING,
                            'available_at' => now()->addMinutes(2 ** min($attempts, 8)),
                        ])->save();
                        $outcome = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'deferred';
                    }
                    $counts[$outcome]++;
                }

                return $counts;
            }));
        } catch (LockTimeoutException) {
            return $counts + ['busy' => 1];
        }
    }

    /** @return string sent|superseded|deferred */
    private function send(MondayOutboxEntry $entry, SocialConnection $connection): string
    {
        $task = Task::query()->withTrashed()->with(['board', 'statusRow'])->find($entry->task_id);
        $kinds = (array) $entry->kinds;
        $itemId = $this->mondayId($task?->external_ref);
        if ($task !== null && $task->trashed()) {
            // Deleted here: archived on Monday (restorable there, as it is here).
            if (in_array('delete', $kinds, true) && $itemId !== null) {
                $this->writer->archiveItem($itemId);

                return $this->close($entry, MondayOutboxEntry::SENT);
            }

            return $this->close($entry, MondayOutboxEntry::SUPERSEDED, 'The task is gone.');
        }
        if ($task === null || $task->sensitive) {
            return $this->close($entry, MondayOutboxEntry::SUPERSEDED, $task?->sensitive ? 'A sensitive task is not sent to Monday.' : 'The task is gone.');
        }
        $boardId = $this->mondayId($task->board?->external_ref);
        if ($boardId === null) {
            return $this->close($entry, MondayOutboxEntry::SUPERSEDED, 'The board no longer answers for a Monday board.');
        }

        if ($itemId === null) {
            if (! in_array('create', $kinds, true)) {
                // An update or edit to a task whose making has not reached Monday yet waits for it.
                return $this->creating($task) ? $this->defer($entry) : $this->close($entry, MondayOutboxEntry::SUPERSEDED, 'The task is not on Monday.');
            }
            $parent = $task->parent_task_id ? Task::query()->withTrashed()->find($task->parent_task_id) : null;
            if ($parent !== null && $this->mondayId($parent->external_ref) === null) {
                // The parent goes first; a parent that will never be on Monday (a branch board's own
                // task from before the merge) keeps its subtasks here too.
                return $this->creating($parent) ? $this->defer($entry)
                    : $this->close($entry, MondayOutboxEntry::SUPERSEDED, 'Its parent task is not on Monday.');
            }
            $itemId = $parent !== null
                ? $this->writer->createSubitem($this->mondayId($parent->external_ref), $task->title)
                : $this->writer->createItem($boardId, $this->groupOn($boardId, $task), $task->title, []);
            // The id first: a retry after this point updates the item, never makes a second one.
            $task->timestamps = false;
            $task->forceFill(['external_ref' => 'monday:'.$itemId])->saveQuietly();
            $task->timestamps = true;
            $kinds = array_values(array_unique([...$kinds, 'status', 'due', 'owners',
                ...TaskColumnValue::query()->where('task_id', $task->id)->pluck('column_id')->map(fn ($id) => 'values:'.$id)->all()]));
        } elseif ($entry->task_update_id === null) {
            $kinds = $this->lastWriter($entry, $task, $itemId, $boardId, $kinds);
            if ($kinds === []) {
                return $this->close($entry, MondayOutboxEntry::SUPERSEDED, 'Monday changed the same fields later.');
            }
        }

        $item = $this->seen[$itemId] ?? $this->reader->itemState($itemId); // read once per send
        unset($this->seen[$itemId]);
        if ($item === null) {
            return $this->close($entry, MondayOutboxEntry::SUPERSEDED, 'Monday no longer shows that item.');
        }
        $mondayAfter = null;
        $itemBoard = $item['board'];
        if ($itemBoard !== $boardId && $task->parent_task_id === null && $entry->task_update_id === null) {
            // Moved to another board here: moved there too (full mirror, desk 11-18), but only when
            // every column holding a value has its twin there: Monday matches columns by title and
            // type and DROPS a value with no match. A missing text column is made; any other stops it.
            $before = $this->reader->valuesOf($itemId);
            $source = $this->meta($itemBoard)['columns'];
            foreach ($before as $col => $text) {
                $m = $source[$col] ?? null;
                // Monday's own (computed) columns, and the status, which each board keeps as its own.
                if ($m === null || in_array($col, ['name', 'subitems'], true) || $col === $this->meta($itemBoard)['status']
                    || in_array($m['type'], ['subtasks', 'creation_log', 'last_updated', 'item_id', 'formula', 'mirror', 'board_relation', 'auto_number'], true)) {
                    continue;
                }
                $twin = MondayBoardReader::columnFor($this->meta($boardId), (string) $m['title']);
                $twinType = $twin !== null ? ($this->meta($boardId)['columns'][$twin]['type'] ?? null) : null;
                if ($twin !== null && $twinType === $m['type']) {
                    continue;
                }
                if ($twin === null && in_array($m['type'], ['text', 'long_text'], true)) {
                    $this->writer->createTextColumn($boardId, (string) $m['title']);
                    unset($this->meta[$boardId]);

                    continue;
                }
                $why = 'Not moved on Monday: the board it moved to has no "'.$m['title'].'" ('.$m['type'].') column, so Monday would drop its value.';
                $this->activity($task, $why);
                throw new \RuntimeException($why);
            }
            $this->writer->moveToBoard($itemId, $boardId, $this->groupOn($boardId, $task));
            $lost = array_diff_key($before, $this->reader->valuesOf($itemId));
            if ($lost !== []) {
                $this->activity($task, 'Moved on Monday, but '.count($lost).' value(s) did not arrive: '.implode(', ', array_map(fn ($c) => $source[$c]['title'] ?? $c, array_keys($lost))));
                report(new \RuntimeException('Monday move dropped values on item '.$itemId));
            }
            $itemBoard = $boardId;
            $item['group'] = null;
        }

        if ($entry->task_update_id !== null) {
            $update = $entry->taskUpdate;
            if ($update !== null && ! str_starts_with((string) $update->external_ref, 'monday:')) {
                $who = $update->user_id ? User::query()->find($update->user_id)?->name : null;
                $id = $this->writer->createUpdate($itemId, trim($update->body)."\n\n— from EzBizi".($who ? ', '.$who : ''));
                $update->forceFill(['external_ref' => 'monday:'.$id])->saveQuietly();
            }
        } else {
            $values = $this->values($task, $kinds, $this->meta($itemBoard));
            if ($values !== []) {
                $mondayAfter = $this->writer->changeValues($itemBoard, $itemId, $values) ?? $mondayAfter;
            }
            if (in_array('group', $kinds, true) && $task->parent_task_id === null) {
                $group = $this->groupOn($itemBoard, $task);
                if ($group !== $item['group']) {
                    $mondayAfter = $this->writer->moveToGroup($itemId, $group) ?? $mondayAfter;
                }
            }
        }

        // What Monday now holds is what the task holds: the read side must not bring it back as a change.
        // From the write's own answer when there was one; else one read.
        $after = $mondayAfter !== null ? ['updated_at' => $mondayAfter] : $this->reader->itemState($itemId);
        $task->timestamps = false;
        $task->forceFill([
            'external_updated_at' => isset($after['updated_at']) ? CarbonImmutable::parse($after['updated_at']) : $task->external_updated_at,
            'external_synced_at' => now(),
        ])->saveQuietly();
        $task->timestamps = true;

        return $this->close($entry, MondayOutboxEntry::SENT);
    }

    /**
     * Last writer wins, per field (desk 11-18: a whole queued change was dropped
     * when Monday touched any field of the item). Monday's activity log since our
     * change names the fields Monday changed after us: those of ours are dropped
     * (Monday's stays, the read side brings it); the rest are sent. Both on the task.
     *
     * @return list<string> the kinds still to send
     */
    private function lastWriter(MondayOutboxEntry $entry, Task $task, string $itemId, string $boardId, array $kinds): array
    {
        $item = $this->seen[$itemId] = $this->reader->itemState($itemId);
        $mondayAt = isset($item['updated_at']) ? CarbonImmutable::parse($item['updated_at']) : null;
        $here = CarbonImmutable::parse($entry->changed_at);
        if ($mondayAt === null || ! $mondayAt->greaterThan($here)) {
            return $kinds; // nothing on Monday after our change
        }
        $changed = $this->reader->changedFields($item['board'] ?? $boardId, $itemId, $here);
        $meta = $this->meta($item['board'] ?? $boardId);
        $lost = array_values(array_filter($kinds, fn (string $k) => match (true) {
            $k === 'title' => in_array('name', $changed, true),
            $k === 'group' => in_array('group', $changed, true),
            $k === 'status' => $meta['status'] !== null && in_array($meta['status'], $changed, true),
            $k === 'due' => $meta['due'] !== null && in_array($meta['due'], $changed, true),
            $k === 'owners' => $meta['assignee'] !== null && in_array($meta['assignee'], $changed, true),
            str_starts_with($k, 'values:') => ($c = TaskColumn::query()->find(substr($k, 7))) !== null
                && in_array(MondayBoardReader::columnFor($meta, $c->name), $changed, true),
            default => false,
        }));
        if ($lost !== []) {
            $names = implode(', ', array_map(fn ($k) => str_starts_with($k, 'values:') ? 'a column' : $k, $lost));
            $this->activity($task, 'Monday changed '.$names.' at '.$mondayAt->format('M j g:i A').', after the change here at '.$here->format('g:i A').": Monday's is kept.");
        }

        return array_values(array_diff($kinds, $lost));
    }

    private function activity(Task $task, string $sentence): void
    {
        TaskActivity::query()->create([
            'organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => null,
            'field' => 'monday_sync', 'label' => 'Monday sync', 'from' => null, 'to' => mb_substr($sentence, 0, 255),
        ]);
    }

    /** The Monday column values for these fields of the task, as Monday's change_multiple_column_values takes them. */
    private function values(Task $task, array $kinds, array $meta): array
    {
        $out = [];
        foreach ($kinds as $kind) {
            if ($kind === 'title') {
                $out['name'] = $task->title;
            } elseif ($kind === 'status' && $meta['status'] !== null && $task->statusRow !== null) {
                $out[$meta['status']] = ['label' => $task->statusRow->name];
            } elseif ($kind === 'due' && $meta['due'] !== null) {
                $out[$meta['due']] = $task->due_on ? ['date' => $task->due_on->toDateString()] : '';
            } elseif ($kind === 'owners' && $meta['assignee'] !== null) {
                $owners = $task->owners()->pluck('user_id')->all();
                $mine = $this->people($owners);
                // Desk 11-18: never shrink Monday's Person column for people we cannot name there. Keep
                // Monday's people with no account here (and teams); ours replace only the ones we know.
                $known = array_flip(array_values($this->mondayIds ?? []));
                $kept = $this->mondayId($task->external_ref) === null ? [] : array_values(array_filter(
                    $this->reader->peopleOn($this->mondayId($task->external_ref), $meta['assignee']),
                    fn (array $p) => $p['kind'] !== 'person' || ! isset($known[(string) $p['id']]),
                ));
                $unmapped = array_diff($owners, array_keys($this->mondayIds ?? []));
                if ($unmapped !== []) {
                    $this->activity($task, "Not on Monday's Person column (no Monday account): ".User::query()->whereIn('id', $unmapped)->pluck('name')->join(', '));
                }
                $all = [...$mine, ...$kept];
                if ($all === [] && $owners !== []) {
                    continue; // only unmapped owners: Monday's column is left as it is
                }
                $out[$meta['assignee']] = ['personsAndTeams' => $all];
            } elseif (str_starts_with($kind, 'values:')) {
                $column = TaskColumn::query()->find(substr($kind, 7));
                $mondayColumn = $column ? MondayBoardReader::columnFor($meta, $column->name) : null;
                $mondayType = $mondayColumn !== null ? $meta['columns'][$mondayColumn]['type'] : null;
                if ($mondayColumn === null && $column?->type === 'text' && isset($meta['board'])) {
                    // A text column made here is made on Monday too, same name (full mirror, Jose 11-18).
                    $mondayColumn = $this->writer->createTextColumn($meta['board'], $column->name);
                    $mondayType = 'text';
                    unset($this->meta[$meta['board']]);
                }
                if ($mondayColumn === null) {
                    continue;
                }
                $value = TaskColumnValue::query()->with('column')->where('task_id', $task->id)->where('column_id', $column->id)->first();
                $encoded = $this->encode($mondayType, $column, $value);
                if ($encoded !== false) {
                    $out[$mondayColumn] = $encoded;
                }
            }
        }

        return $out;
    }

    /** A Task Board value as Monday's column type takes it; '' clears; false: Monday's column is not written (formula, mirror…). */
    private function encode(string $type, TaskColumn $column, ?TaskColumnValue $v): mixed
    {
        if ($v === null) {
            return in_array($type, ['formula', 'mirror', 'lookup', 'board_relation', 'dependency', 'file', 'creation_log', 'last_updated', 'item_id', 'subtasks'], true) ? false : '';
        }
        $value = TasksController::valueOf($v);
        $label = fn (string $id) => collect($column->options())->firstWhere('id', $id)['label'] ?? null;

        return match ($type) {
            'status', 'color' => ['label' => is_string($value) ? ($label($value) ?? (string) ColumnConverter::words($column, $v)) : (string) ColumnConverter::words($column, $v)],
            'dropdown', 'tags' => ['labels' => array_values(array_filter(array_map($label, (array) $value))) ?: array_map('trim', explode(',', (string) ColumnConverter::words($column, $v)))],
            'people', 'multiple-person' => ['personsAndTeams' => $this->people(is_array($value) ? array_values($value) : array_filter([is_string($value) ? $value : null]))],
            'date' => ['date' => substr((string) $value, 0, 10)],
            'timeline', 'timerange' => is_array($value) && isset($value['from'], $value['to']) ? ['from' => $value['from'], 'to' => $value['to']] : '',
            'numbers', 'numeric' => is_numeric($value) ? (string) $value : '',
            'rating' => is_numeric($value) ? ['rating' => (int) $value] : '',
            'location' => is_array($value) && ! empty($value['address']) ? array_filter(['address' => $value['address'], 'lat' => $value['lat'] ?? null, 'lng' => $value['lng'] ?? null], fn ($x) => $x !== null) : '',
            'board_relation', 'dependency' => ['item_ids' => array_values(array_filter(array_map(fn ($ref) => (int) $this->mondayId($ref),
                Task::query()->whereIn('id', (array) $value)->pluck('external_ref')->all())))],
            'checkbox', 'boolean' => $value ? ['checked' => 'true'] : '',
            'link' => ['url' => (string) $value, 'text' => (string) $value],
            'email' => ['email' => (string) $value, 'text' => (string) $value],
            'phone' => ['phone' => preg_replace('/[^\d+]/', '', (string) $value)],
            'long_text', 'long-text' => ['text' => (string) $value],
            'text', 'name' => (string) (ColumnConverter::words($column, $v) ?? ''),
            default => false,
        };
    }

    /** @param  list<string>  $userIds  @return list<array{id: int, kind: string}> */
    private function people(array $userIds): array
    {
        if ($this->mondayIds === null) {
            $this->mondayIds = [];
            $people = User::query()->get();
            $words = fn (?string $n) => array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim((string) $n))) ?: []));
            foreach ($this->reader->users() as $u) {
                $mapped = $this->map[mb_strtolower(trim((string) $u['email']))] ?? null;
                $match = ($mapped !== null ? $people->first(fn (User $p) => mb_strtolower($p->email) === $mapped) : null)
                    ?? $people->first(fn (User $p) => $u['email'] && mb_strtolower($p->email) === mb_strtolower((string) $u['email']))
                    ?? (($same = $people->filter(fn (User $p) => array_diff($words($u['name']), $words($p->name)) === [] && $words($u['name']) !== []))->count() === 1 ? $same->first() : null);
                if ($match !== null) {
                    $this->mondayIds[$match->id] ??= (string) $u['monday_id'];
                }
            }
        }

        return array_values(array_map(fn ($id) => ['id' => (int) $this->mondayIds[$id], 'kind' => 'person'],
            array_filter($userIds, fn ($id) => isset($this->mondayIds[$id]))));
    }

    /** A making of the task is still on its way to Monday. */
    private function creating(Task $task): bool
    {
        return MondayOutboxEntry::query()->where('task_id', $task->id)->where('state', MondayOutboxEntry::PENDING)
            ->whereJsonContains('kinds', 'create')->exists();
    }

    private function defer(MondayOutboxEntry $entry): string
    {
        $entry->forceFill(['available_at' => now()->addMinute()])->save();

        return 'deferred';
    }

    /**
     * The Monday group of the task's group name; made there when Monday has none
     * (full mirror, Jose 11-18), right after the Monday group of the EzBizi group
     * before it, else after Monday's last group. Never null: Monday files an item
     * with no group in its FIRST group (Company Info, desk 11-18), so a task with
     * no group is refused, not sent.
     */
    private function groupOn(string $boardId, Task $task): string
    {
        $name = trim((string) $task->group?->name);
        if ($name === '') {
            throw new \RuntimeException('The task has no group; it is not sent to Monday until it has one.');
        }
        $groups = $this->meta($boardId)['groups'];
        $ref = (string) $task->group->external_ref;
        if (str_starts_with($ref, 'monday:') && in_array(substr($ref, 7), $groups, true)) {
            return substr($ref, 7); // the Monday group it mirrors, whatever either side calls it now
        }
        if (isset($groups[mb_strtolower($name)])) {
            return $groups[mb_strtolower($name)];
        }
        $after = null;
        foreach (TaskGroup::query()->where('board_id', $task->board_id)->where('position', '<', (int) $task->group->position)->orderByDesc('position')->pluck('name') as $before) {
            if (isset($groups[mb_strtolower(trim($before))])) {
                $after = $groups[mb_strtolower(trim($before))];
                break;
            }
        }
        $after ??= array_values($groups)[count($groups) - 1] ?? throw new \RuntimeException('Monday shows no group on that board to place a new one after.');

        $made = $this->writer->createGroup($boardId, $name, $after);
        $task->group->forceFill(['external_ref' => 'monday:'.$made])->saveQuietly();

        return $this->meta[$boardId]['groups'][mb_strtolower($name)] = $made;
    }

    private function meta(string $boardId): array
    {
        return $this->meta[$boardId] ??= $this->reader->writeMeta($boardId) + ['board' => $boardId];
    }

    private function mondayId(?string $ref): ?string
    {
        return is_string($ref) && preg_match('/^monday:(\d+)$/', $ref, $m) ? $m[1] : null;
    }

    /** Failed for good, with the reason, and a line on the task so its people know it did not reach Monday. */
    private function refused(MondayOutboxEntry $entry, \Throwable $e): string
    {
        $task = Task::query()->withTrashed()->find($entry->task_id);
        $itemId = $this->mondayId($task?->external_ref);
        try {
            $state = $itemId === null ? null : ($this->reader->itemState($itemId)['state'] ?? null);
        } catch (\Throwable) {
            $state = null;
        }
        $why = match ($state) {
            'archived' => 'The item is archived on Monday, which takes no changes to it.',
            'deleted' => 'The item is deleted on Monday.',
            null => 'Monday shows no such item to this account any more.',
            default => 'Monday refused this account the change (Access denied).',
        };
        $entry->forceFill(['state' => MondayOutboxEntry::FAILED, 'attempts' => $entry->attempts + 1, 'error' => $why.' '.mb_substr($e->getMessage(), 0, 300)])->save();
        if ($task !== null) {
            $this->activity($task, 'Not sent to Monday: '.$why);
        }

        return 'failed';
    }

    private function close(MondayOutboxEntry $entry, string $state, ?string $why = null): string
    {
        $entry->forceFill(['state' => $state, 'sent_at' => $state === MondayOutboxEntry::SENT ? now() : null, 'error' => $why])->save();

        return $state === MondayOutboxEntry::SENT ? 'sent' : 'superseded';
    }
}
