<?php

namespace App\Support\Tasks\Monday;

use App\Jobs\MakeVideoPoster;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\MondayOutboxEntry;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardFolder;
use App\Models\TaskBoardMember;
use App\Models\TaskBoardView;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Files\AttachmentUploader;
use App\Support\Tasks\ColumnConverter;
use App\Support\Tasks\JobTitle;
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

    /** @var array<string, true> Monday items whose "Job #N" title was cleaned here, to send back */
    private array $retitled = [];

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

    /** Take Monday's fields even where Monday has not changed since we took them (a mapping fixed later). */
    private bool $reapply = false;

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
    public function run(Organization $org, array $data, string|User $as, bool $dry = false, ?string $filesDir = null, bool $refresh = false, array $sensitiveItems = [], bool $reapply = false): array
    {
        $this->reset($data, $dry, $filesDir, $refresh);
        $this->reapply = $reapply;
        $this->sensitiveItems = array_map('strval', $sensitiveItems);

        // Imports are history, not events: no rule texts anyone about them.
        // Put back afterwards, so a queue worker's next job has its events.
        app()->instance(EventEmitter::class, new class extends EventEmitter
        {
            public function __construct() {}

            public function emitAfterCommit(string $eventKey, ?Model $subject = null, array $payload = []): void {}
        });
        $this->writer = app()->make(TaskWriter::class);
        // The first import is history: no bells. The sync is live work: a person given a task on
        // Monday hears about it (desk 11-19: Jose's bell never rang for Monday's assignments).
        $this->writer->quiet = ! $refresh;

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
                        // One bad item never stops the rest (desk 11-18: one item stopped 29 syncs).
                        try {
                            DB::transaction(function () use ($board, $item, $actor) {
                                $parent = $this->task($board, $item, null, $actor);
                                foreach ($item['subitems'] ?? [] as $sub) {
                                    $this->task($board, $sub + ['group' => $item['group']], $parent, $actor);
                                }
                            });
                        } catch (\Throwable $e) {
                            report($e);
                            $this->counts['items failed'] = ($this->counts['items failed'] ?? 0) + 1;
                        }
                    }
                }
            }));
            if (! $this->dry && $this->retitled !== []) {
                // Outside the suppression: the cleaned titles go back to Monday (live rows).
                $this->tenant->run($org, function () {
                    foreach (Task::query()->whereIn('external_ref', array_map(fn ($i) => 'monday:'.$i, array_keys($this->retitled)))->get() as $t) {
                        app(MondayOutbox::class)->record($t, ['title']);
                    }
                });
            }
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
        $this->unmatched = $this->ownersDropped = $this->notCarried = $this->assignable = $this->map = $this->mondayNames = $this->retitled = [];
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
            // The sync: someone who joined since the last read gets the boards Monday shares with them.
            $board = TaskBoard::query()->findOrFail($b['target_board_id']);
            $this->members($board, $b['members'] ?? []);
            // Monday's board name, as Monday's users know it (Jose 11-18: no differences between them).
            if (($name = mb_substr(trim((string) ($b['name'] ?? '')), 0, 255)) !== '' && $board->name !== $name) {
                $board->forceFill(['name' => $name])->saveQuietly();
                $this->counts['boards renamed'] = ($this->counts['boards renamed'] ?? 0) + 1;
            }

            return $board;
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
     * @return array{0: ?Company, 1: ?Franchise}
     */
    private function place(array $scope): array
    {
        $find = function (string $model, string $name, ?string $companyId = null) {
            $rows = $model::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->get()
                ->filter(fn ($r) => mb_strtolower(trim((string) $r->name)) === mb_strtolower(trim($name)));

            return $rows->count() === 1 ? $rows->first()
                : throw new \RuntimeException(sprintf('The map names %s "%s", which is %s here.', class_basename($model), $name, $rows->isEmpty() ? 'nothing' : 'more than one place'));
        };
        $company = ! empty($scope['company']) ? $find(Company::class, (string) $scope['company']) : null;
        $franchise = ! empty($scope['franchise']) ? $find(Franchise::class, (string) $scope['franchise'], $company?->id) : null;

        return [$company, $franchise];
    }

    /**
     * Monday's owners and subscribers become the board's owners and members,
     * on a branch or company board too (Nehorai, CT GM, 11-17: his branch
     * board refused his tasks). Not a personal board: that is its person's.
     * Added, never removed; somebody with no account here is counted, not invented.
     */
    private function members(TaskBoard $board, array $members): void
    {
        if ($board->kind === TaskBoard::KIND_USER || $members === []) {
            return;
        }
        $have = TaskBoardMember::query()->where('board_id', $board->id)->pluck('role', 'user_id')->all();
        foreach ($members as $m) {
            $user = $this->person($m['email'] ?? null);
            // Add-only, and never someone an admin took off this board here (desk 11-17).
            if ($user === null || ! $user->is_active || isset($have[$user->id])
                || in_array($user->id, (array) ($board->builtin_columns['_removed'] ?? []), true)) {
                continue;
            }
            $role = ($m['role'] ?? '') === 'owner' ? TaskBoardMember::OWNER : TaskBoardMember::MEMBER;
            TaskBoardMember::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id, 'user_id' => $user->id, 'role' => $role]);
            $have[$user->id] = $role;
            $this->counts['members added'] = ($this->counts['members added'] ?? 0) + 1;
        }
        unset($this->assignable[$board->id]);
    }

    /**
     * After a board move, each value goes to the new board's column of the same
     * name and type, as Monday does on its own move (desk 11-18); a status or
     * dropdown keeps its label. Only a value with no such column is dropped.
     */
    public static function carryValues(Task $task, TaskBoard $board): void
    {
        $there = TaskColumn::query()->where('board_id', $board->id)->get();
        $label = fn (TaskColumn $c, ?string $id) => collect($c->settings['options'] ?? [])->firstWhere('id', $id)['label'] ?? null;
        foreach (TaskColumnValue::query()->with('column')->where('task_id', $task->id)
            ->whereNotIn('column_id', $there->pluck('id'))->get() as $v) {
            $from = $v->column;
            $to = $from ? $there->first(fn (TaskColumn $c) => $c->type === $from->type && mb_strtolower(trim($c->name)) === mb_strtolower(trim($from->name))) : null;
            if ($to === null || TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $to->id)->exists()) {
                $v->delete();

                continue;
            }
            if (in_array($from->type, ['status', 'dropdown'], true) && $v->value_text !== null) {
                $name = $label($from, $v->value_text);
                $opt = collect($to->settings['options'] ?? [])->first(fn ($o) => mb_strtolower((string) ($o['label'] ?? '')) === mb_strtolower((string) $name));
                if ($opt === null) {
                    $v->delete();

                    continue;
                }
                $v->value_text = (string) $opt['id'];
            }
            $v->column_id = $to->id;
            $v->save();
        }
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
        if ($existing !== null && ! $existing->trashed() && in_array($item['state'] ?? 'active', ['archived', 'deleted'], true)) {
            // Archived or deleted on Monday: deleted here too, restorable (never a hard delete), and logged.
            $this->writer->delete($existing, null);
            TaskActivity::query()->create(['organization_id' => $existing->organization_id, 'task_id' => $existing->id, 'user_id' => null,
                'field' => 'monday_sync', 'label' => 'Monday sync', 'from' => null, 'to' => 'Moved to trash: '.$item['state'].' on Monday.']);
            $this->counts['trashed (gone on Monday)'] = ($this->counts['trashed (gone on Monday)'] ?? 0) + 1;

            return $existing;
        }
        if ($existing === null && in_array($item['state'] ?? 'active', ['archived', 'deleted'], true)) {
            return null; // never brought in only to be trashed
        }
        if ($existing !== null && ! $existing->trashed() && $existing->board_id !== $board->id
            && $existing->external_synced_at !== null && ! $existing->updated_at->greaterThan($existing->external_synced_at)) {
            // Nothing changed here since the last sync, so the board change is Monday's (desk 11-18: a
            // Georgia task moved to Texas on Monday stayed on Georgia here). Follow it, its old board's values dropped.
            $this->writer->move($existing, $this->group($board, $item['group'] ?? null), null, null);
            self::carryValues($existing, $board);
            $existing->refresh();
            $this->counts['moved here (moved on Monday)'] = ($this->counts['moved here (moved on Monday)'] ?? 0) + 1;
        }
        if ($existing !== null && ! $existing->trashed() && $existing->board_id !== $board->id) {
            // Moved to another board here: EzBizi's place wins; Monday's notes still arrive,
            // but its columns, group and status belong to the board it left (desk 11-18).
            $this->updates($existing, $item, $as);
            $this->counts['kept (moved to another board here)'] = ($this->counts['kept (moved to another board here)'] ?? 0) + 1;

            return $existing;
        }
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
            // On the sync, made by the Monday item's creator when they have an account here, else by
            // nobody (Monday), never by the connection's own user (desk 11-19).
            : $this->writer->create($board, $input + ['parent_task_id' => $parent?->id], $this->refresh ? $this->person($item['creator_email'] ?? null) : $as);

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

        $mondayChanged = $this->reapply || ($task->external_updated_at === null
            ? $mondayAt->greaterThan($task->updated_at)
            : $mondayAt->greaterThan($task->external_updated_at));
        if (! $mondayChanged) {
            $this->counts['skipped (already imported)']++;

            return;
        }
        $hereChanged = $task->external_synced_at !== null && $task->updated_at->greaterThan($task->external_synced_at);
        $input = $this->input($board, $item);
        // A field still waiting in the outbox is newer here than anything Monday shows: Monday's
        // older value never overwrites it (desk 11-18: 189 renamed titles were put back).
        // Only a change made after Monday's: a later Monday change still wins (last writer).
        $waiting = MondayOutboxEntry::query()->where('task_id', $task->id)->where('state', MondayOutboxEntry::PENDING)
            ->whereNull('task_update_id')->where('changed_at', '>=', $mondayAt)->pluck('kinds')->flatten()->unique()->all();
        foreach ($waiting as $k) {
            match (true) {
                $k === 'title' => $input = array_diff_key($input, ['title' => 1]),
                $k === 'group' => $input = array_diff_key($input, ['group' => 1]),
                $k === 'status' => $input = array_diff_key($input, ['status' => 1]),
                $k === 'due' => $input = array_diff_key($input, ['due_on' => 1]),
                $k === 'owners' => $input = array_diff_key($input, ['assignee_user_ids' => 1]),
                str_starts_with((string) $k, 'values:') => $input['values'] = array_diff_key($input['values'] ?? [], [substr($k, 7) => 1]),
                default => null,
            };
        }
        if ($hereChanged && ! $mondayAt->greaterThan($task->updated_at)) {
            // Both changed, the change here later: per field (desk 11-18: an owner fix here kept 55
            // missed calls open that people had moved to Done on Monday). The fields changed here
            // since the last sync stay; Monday's other fields are taken.
            $mine = TaskActivity::query()->where('task_id', $task->id)->where('created_at', '>', $task->external_synced_at)->pluck('field')->unique()->all();
            $map = ['title' => 'title', 'group' => 'group', 'status' => 'status', 'due' => 'due_on', 'owner' => 'assignee_user_ids'];
            foreach ($mine as $f) {
                if (isset($map[$f])) {
                    unset($input[$map[$f]]);
                } elseif (isset($input['values'][$f])) {
                    unset($input['values'][$f]);
                }
            }
            if (array_diff(array_keys($input), ['values']) === [] && empty($input['values'])) {
                $this->counts['kept (changed here later)']++;
                $this->quietly($task, ['external_updated_at' => $mondayAt]);

                return;
            }
            $this->counts['merged (each side its own fields)'] = ($this->counts['merged (each side its own fields)'] ?? 0) + 1;
        }

        if ($parent !== null || $task->parent_task_id !== null) {
            unset($input['group']); // a subtask stays in its parent's group
        }
        // A task a rule made carries the rule's people. Monday's own "assign
        // creator" puts our API user (the connection's person) on the copy, and
        // every later status sync brought that back over them (2026-11-17: Ana
        // and Tiara replaced by Jose on 22 missed-call tasks).
        if ($task->created_by_automation_id !== null) { // and a lagging copy put Gino's predecessors back (21:05): Monday's People never override a rule's
            unset($input['assignee_user_ids']);
        }
        // A change from Monday is Monday's, not the connection user's (desk 11-19).
        $this->writer->update($task->load('board'), $input, $this->refresh ? null : $as);
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

        // A "Job #N - Name" title from Monday: N to its column, the title the name (Jose 11-19),
        // and the cleaned title goes back to Monday after the import.
        $title = trim((string) $item['name']);
        $about = [];
        if (($job = JobTitle::split($title)) !== null) {
            $found = JobTitle::job($job['number']);
            $column = $this->column($board, $job['ezbizi'] ? TaskWriter::EZBIZI_JOB_COLUMN : TaskWriter::WORKIZ_JOB_COLUMN, 'text');
            $values[$column->id] = $job['number'];
            $title = $job['rest'] !== '' ? $job['rest'] : ($found?->customer?->name ?? $title);
            if ($found !== null) {
                $about = ['about_type' => 'job', 'about_id' => $found->id];
            }
            if ($title !== trim((string) $item['name']) && isset($item['monday_id'])) {
                $this->retitled[(string) $item['monday_id']] = true;
            }
        }

        return $about + [
            'title' => mb_substr($title ?: '(untitled)', 0, 255),
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
        self::carryValues($task, $task->board()->first());
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
        // Groups mirror Monday's on every board, branch and company ones too (Jose 11-18: "the exact
        // same groups"): Monday's names, colours and order. A group is found by the Monday group it
        // mirrors first, so a rename on Monday renames it here (same group, its tasks stay).
        // Statuses keep the 11-16 rule: a merged branch or company board keeps its own.
        $mirror = in_array($board->kind, [TaskBoard::KIND_CUSTOM, TaskBoard::KIND_USER], true);
        $groups = array_values($b['groups'] ?? []);
        $mine = $board->groups()->get();
        foreach ($groups as $i => $g) {
            $ref = isset($g['monday_id']) ? 'monday:'.$g['monday_id'] : null;
            $group = ($ref ? $mine->firstWhere('external_ref', $ref) : null)
                ?? $mine->first(fn (TaskGroup $x) => $x->external_ref === null && mb_strtolower(trim($x->name)) === mb_strtolower(trim($g['name'])))
                ?? $this->group($board, $g['name'], $g['colour'] ?? null);
            if ($group->name !== trim($g['name'])) {
                $this->counts['groups renamed'] = ($this->counts['groups renamed'] ?? 0) + 1;
            }
            $group->forceFill(array_filter(['name' => trim($g['name']) ?: null, 'colour' => $g['colour'] ?? null, 'position' => $i + 1, 'external_ref' => $ref], fn ($v) => $v !== null))->save();
        }
        if ($groups !== []) {
            $refs = array_map(fn ($g) => 'monday:'.$g['monday_id'], array_filter($groups, fn ($g) => isset($g['monday_id'])));
            $names = array_map(fn ($g) => mb_strtolower(trim($g['name'])), $groups);
            foreach ($board->groups()->orderBy('position')->get() as $i => $group) {
                if (in_array($group->external_ref, $refs, true) || in_array(mb_strtolower(trim($group->name)), $names, true)) {
                    continue;
                }
                // Not on Monday: an EMPTY starter group goes; one holding tasks stays (after Monday's)
                // and the push makes it on Monday. Nothing with tasks is ever removed.
                if (in_array($group->name, array_column(TaskBoards::DEFAULT_GROUPS, 0), true) && ! Task::query()->withTrashed()->where('group_id', $group->id)->exists()) {
                    $group->delete();
                    $this->counts['starter groups removed'] = ($this->counts['starter groups removed'] ?? 0) + 1;
                } else {
                    $group->forceFill(['position' => count($groups) + 1 + $i])->save();
                }
            }
        }

        foreach ($b['statuses'] ?? [] as $s) {
            $this->status($board, $s['label'], (bool) ($s['done'] ?? false), $s['colour'] ?? null);
        }
        // A board whose status came from the wrong column (the PM boards' Area, before
        // 2026-11-13) keeps those labels as statuses nobody is on: they go once unused, and only
        // when they are another Monday column's labels and not the status column's own.
        if (! empty($b['statuses']) && $mirror) {
            $own = array_map(fn ($s) => mb_strtolower($s['label']), $b['statuses']);
            $others = [];
            foreach ($b['columns'] ?? [] as $c) {
                foreach ($c['settings']['options'] ?? [] as $o) {
                    $others[] = mb_strtolower((string) $o['label']);
                }
            }
            foreach ($board->statuses()->whereNull('key')->get() as $s) {
                $name = mb_strtolower($s->name);
                if (! in_array($name, $own, true) && in_array($name, $others, true)
                    && ! Task::query()->withTrashed()->where('status_id', $s->id)->exists()) {
                    $s->delete();
                    $this->counts['stray statuses removed'] = ($this->counts['stray statuses removed'] ?? 0) + 1;
                }
            }
        }

        foreach (array_values($b['columns'] ?? []) as $i => $c) {
            $column = $this->column($board, $c['title'], $c['type'], (array) ($c['settings'] ?? []));
            if ($mirror && $column->position !== $i + 1) {
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
        if ($path === null || $path === [] || $board->folder_id !== null || ! in_array($board->kind, [TaskBoard::KIND_CUSTOM, TaskBoard::KIND_USER], true)) {
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
     * done status. A blank label (Monday's empty status) is "Not started", so a
     * label emptied on Monday — or taken from the wrong column earlier (the PM
     * boards' Area, 2026-11-13) — is emptied here too.
     */
    private function status(TaskBoard $board, ?string $label, bool $done, ?string $colour = null): ?string
    {
        $label = trim((string) $label);
        $statuses = $board->statuses()->orderBy('position')->get();
        if ($label === '') {
            return $done ? $statuses->firstWhere('is_done', true)?->name
                : $statuses->firstWhere('key', Task::STATUS_NOT_STARTED)?->name;
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
        $all = $board->columns()->get();
        $found = $all->first(fn (TaskColumn $c) => mb_strtolower($c->name) === mb_strtolower($title));
        if ($found !== null && $found->type !== $type && ! in_array($board->kind, [TaskBoard::KIND_CUSTOM, TaskBoard::KIND_USER], true)) {
            // A branch board's own column of that name and another type stays as it is: Monday's
            // is "<name> (Monday)" beside it (MondayBoardMerge names it so).
            $title = mb_substr($title, 0, 240).' (Monday)';
            $found = $all->first(fn (TaskColumn $c) => mb_strtolower($c->name) === mb_strtolower($title));
        }
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
