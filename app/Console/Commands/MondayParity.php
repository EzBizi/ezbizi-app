<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Support\Tasks\Monday\MondayBoardReader;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tasks\Monday\MondayOutbox;
use App\Support\Tasks\Monday\MondaySync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Jose 11-18: "it should be full sync, that means everything must be mirrored".
 * Per mirrored board: groups and tasks that exist on one side only. --fix
 * queues ours for Monday (outbox create) and imports Monday's here.
 * ponytail: compares existence (groups, items, subitems), not every value
 * and owner; add a value diff when a drift in values is seen.
 */
class MondayParity extends Command
{
    protected $signature = 'ezbizi:monday-parity {--fix : queue what Monday lacks, import what we lack}';

    protected $description = 'Compare every Monday-mirrored board with Monday: what exists on one side only';

    public function handle(TenantContext $tenant, MondaySync $sync): int
    {
        $drift = 0;
        foreach (SocialConnection::query()->withoutGlobalScopes()->where('provider', 'monday')->get() as $connection) {
            $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
            $reader = new MondayBoardReader(MondayClient::for($connection));
            $missingThere = $tenant->run($org, function () use ($reader, &$drift) {
                $queue = [];
                $boards = TaskBoard::query()->where('external_ref', 'like', 'monday:%')->whereNull('archived_at')->orderBy('name')->get();
                foreach ($boards as $board) {
                    $mondayId = substr((string) $board->external_ref, 7);
                    $meta = $reader->board($mondayId);
                    $mondayGroups = array_map(fn ($g) => mb_strtolower(trim($g['name'])), $meta['groups']);
                    $groupsHere = TaskGroup::query()->where('board_id', $board->id)->pluck('name')
                        ->filter(fn ($n) => ! in_array(mb_strtolower(trim($n)), $mondayGroups, true))->values()->all();

                    $items = $reader->changedSince($mondayId, null)['ids'];
                    $known = Task::query()->withTrashed()->where('external_ref', 'like', 'monday:%')
                        ->pluck('external_ref')->map(fn ($r) => substr($r, 7))->flip();
                    $onlyMonday = array_values(array_filter($items, fn ($id) => ! $known->has($id)));
                    $onlyHere = Task::query()->where('board_id', $board->id)->whereNull('external_ref')->where('sensitive', false)->get();

                    $n = count($groupsHere) + count($onlyMonday) + $onlyHere->count();
                    $drift += $n;
                    $this->line(sprintf('%s: groups only here %d, tasks only here %d, items only on Monday %d', $board->name, count($groupsHere), $onlyHere->count(), count($onlyMonday)));
                    $queue[$mondayId] = ['here' => $onlyHere, 'monday' => $onlyMonday];
                }

                return $queue;
            });

            if ($this->option('fix')) {
                foreach ($missingThere as $mondayId => $gap) {
                    $tenant->run($org, function () use ($gap) {
                        // Parents first, so a subtask finds its parent's item.
                        foreach ($gap['here']->sortBy(fn (Task $t) => $t->parent_task_id === null ? 0 : 1) as $task) {
                            app(MondayOutbox::class)->record($task, ['create']);
                        }
                    });
                    if ($gap['monday'] !== []) {
                        $sync->run($connection, only: [(string) $mondayId], items: $gap['monday']);
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
