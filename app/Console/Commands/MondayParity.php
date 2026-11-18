<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Support\Tasks\Monday\MondayBoardReader;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tasks\Monday\MondayOutbox;
use App\Support\Tasks\Monday\MondaySync;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Jose 11-18: "it should be full sync, that means everything must be mirrored".
 * Per mirrored board: groups and tasks on one side only, tasks moved here
 * but not there, items gone on Monday. --fix queues ours for Monday (create,
 * move), imports Monday's here, and deletes here what Monday removed.
 * ponytail: compares existence (groups, items, subitems), not every value
 * and owner; add a value diff when a drift in values is seen.
 */
class MondayParity extends Command
{
    protected $signature = 'ezbizi:monday-parity {--fix : queue what Monday lacks, import what we lack} {--groups-only : with --fix, only settle which group an item is in} {--boards= : only these boards, by name, comma separated}';

    protected $description = 'Compare every Monday-mirrored board with Monday: what exists on one side only';

    public function handle(TenantContext $tenant, MondaySync $sync): int
    {
        $drift = 0;
        foreach (SocialConnection::query()->withoutGlobalScopes()->where('provider', 'monday')->get() as $connection) {
            $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
            $reader = new MondayBoardReader(MondayClient::for($connection));
            $missingThere = $tenant->run($org, function () use ($reader, &$drift) {
                $boards = TaskBoard::query()->where('external_ref', 'like', 'monday:%')->whereNull('archived_at')
                    // Names, comma separated; a name with % is a LIKE pattern ("%Project Management%").
                    ->when($this->option('boards'), fn ($q, $names) => $q->where(function ($w) use ($names) {
                        foreach (array_map('trim', explode(',', $names)) as $n) {
                            str_contains($n, '%') ? $w->orWhere('name', 'like', $n) : $w->orWhere('name', $n);
                        }
                    }))
                    ->orderBy('name')->get();
                // Every live Monday item, by board: an item here on another board was moved here, one on no board is gone there.
                $where = [];
                $groupOf = [];
                foreach ($boards as $board) {
                    foreach ($reader->itemGroups(substr((string) $board->external_ref, 7)) as $id => $groupTitle) {
                        $where[(string) $id] = $board->id;
                        $groupOf[(string) $id] = mb_strtolower(trim($groupTitle));
                    }
                }
                $queue = [];
                foreach ($boards as $board) {
                    $mondayId = substr((string) $board->external_ref, 7);
                    $meta = $reader->board($mondayId);
                    $mondayGroups = array_map(fn ($g) => mb_strtolower(trim($g['name'])), $meta['groups']);
                    $renamed = trim($meta['name']) !== trim($board->name) ? 1 : 0;
                    $groupsHere = TaskGroup::query()->where('board_id', $board->id)->whereIn('id', Task::query()->where('board_id', $board->id)->select('group_id'))->pluck('name')
                        ->filter(fn ($n) => ! in_array(mb_strtolower(trim($n)), $mondayGroups, true))->values()->all();

                    $ours = Task::query()->withTrashed()->with('group:id,name')->where('board_id', $board->id)->whereNull('parent_task_id')
                        ->where('external_ref', 'like', 'monday:%')->get(['id', 'board_id', 'group_id', 'external_ref', 'deleted_at', 'updated_at', 'external_synced_at']);
                    $known = Task::query()->withTrashed()->where('external_ref', 'like', 'monday:%')->pluck('external_ref')->map(fn ($r) => substr($r, 7))->flip();
                    $onlyMonday = array_keys(array_filter($where, fn ($b, $id) => $b === $board->id && ! $known->has((string) $id), ARRAY_FILTER_USE_BOTH));
                    $live = $ours->whereNull('deleted_at');
                    $moved = $live->filter(fn ($t) => isset($where[substr($t->external_ref, 7)]) && $where[substr($t->external_ref, 7)] !== $board->id)->pluck('id')->all();
                    // Same item, different group: the later side wins (ours newer: pushed; Monday's: re-read).
                    $regroup = $live->filter(fn ($t) => ($where[substr($t->external_ref, 7)] ?? null) === $board->id
                        && $groupOf[substr($t->external_ref, 7)] !== mb_strtolower(trim((string) $t->group?->name)));
                    $pushGroup = $regroup->filter(fn ($t) => $t->external_synced_at === null || $t->updated_at->greaterThan($t->external_synced_at))->pluck('id')->all();
                    $pullGroup = $regroup->reject(fn ($t) => in_array($t->id, $pushGroup, true))->map(fn ($t) => substr($t->external_ref, 7))->values()->all();
                    $gone = $live->filter(fn ($t) => ! isset($where[substr($t->external_ref, 7)]))->pluck('id')->all();
                    $onlyHere = Task::query()->where('board_id', $board->id)->whereNull('external_ref')->where('sensitive', false)->get();

                    $n = count($groupsHere) + count($onlyMonday) + $onlyHere->count() + count($moved) + count($gone) + $regroup->count() + $renamed;
                    $drift += $n;
                    $this->line(sprintf('%s: groups only here %d, tasks only here %d, items only on Monday %d, moved here not there %d, gone on Monday %d, in another group %d, name differs %d',
                        $board->name, count($groupsHere), $onlyHere->count(), count($onlyMonday), count($moved), count($gone), $regroup->count(), $renamed));
                    $queue[$mondayId] = ['here' => $onlyHere, 'monday' => array_map('strval', $onlyMonday), 'moved' => $this->option('groups-only') ? $pushGroup : [...$moved, ...$pushGroup], 'gone' => $gone, 'pull' => $pullGroup, 'board' => $board->id, 'name' => $renamed ? trim($meta['name']) : null];
                }

                return $queue;
            });

            if ($this->option('fix')) {
                foreach ($missingThere as $mondayId => $gap) {
                    if ($this->option('groups-only')) {
                        // Every 30 minutes (desk 11-18): which group, nothing else; Monday's moves first.
                        $gap = ['here' => collect(), 'monday' => [], 'gone' => [], 'name' => null] + $gap;
                    }
                    $tenant->run($org, function () use ($gap) {
                        if ($gap['name'] !== null) { // Monday's name wins (desk 11-18)
                            TaskBoard::query()->whereKey($gap['board'])->update(['name' => mb_substr($gap['name'], 0, 255)]);
                        }
                        // Parents first, so a subtask finds its parent's item.
                        foreach ($gap['here']->sortBy(fn (Task $t) => $t->parent_task_id === null ? 0 : 1) as $task) {
                            app(MondayOutbox::class)->record($task, ['create']);
                        }
                        foreach (Task::query()->whereIn('id', $gap['moved'])->get() as $task) {
                            app(MondayOutbox::class)->record($task, ['group']); // the push moves the item to this board
                        }
                        // Archived or deleted on Monday: deleted here too (restorable), never sent back.
                        TaskWriter::bulk(function (TaskWriter $w) use ($gap) { // no events, no outbox
                            foreach (Task::query()->whereIn('id', $gap['gone'])->get() as $task) {
                                $w->delete($task, null);
                                TaskActivity::query()->create(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => null,
                                    'field' => 'monday_sync', 'label' => 'Monday sync', 'from' => null, 'to' => 'Moved to trash: no longer on Monday (parity check).']);
                            }
                        });
                    });
                    if ($gap['monday'] !== [] || $gap['pull'] !== []) {
                        $sync->run($connection, only: [(string) $mondayId], items: [...$gap['monday'], ...$gap['pull']], reapply: true);
                    }
                }
            }
        }

        $this->info("Drift: {$drift}".($this->option('fix') ? ' (queued and imported)' : ''));
        if ($drift > 0) {
            Log::warning('Monday parity: drift', ['count' => $drift]);
        }

        return $drift > 0 && ! $this->option('fix') ? self::FAILURE : self::SUCCESS;
    }
}
