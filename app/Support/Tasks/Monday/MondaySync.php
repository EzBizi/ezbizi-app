<?php

namespace App\Support\Tasks\Monday;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Monday → Task Board, for the boards imported from Monday (desk, 2026-11-12;
 * docs/agents/monday-sync-design-2026-11-12.md). Read only: nothing here writes
 * to Monday.
 *
 * Which boards: every live Task Board carrying external_ref monday:<board id>
 * in the connection's organization. A board the import filled before boards
 * were stamped is adopted first: Monday is asked which board one of its items
 * is on.
 *
 * The poll (ezbizi:monday-sync, every 10 minutes) reads the items changed
 * since the board's cursor; a webhook reads the one item it names. Either way
 * the items go through MondayImport with refresh on: new items arrive, changed
 * ones take Monday's fields under the conflict rule, new updates and files
 * arrive.
 *
 * State on the connection's `setup.monday`: `as` (who connected: the actor),
 * `people` (monday email => EzBizi email), `cursors` (board id => the newest
 * updated_at seen) and `last` (the last run's counts).
 */
final class MondaySync
{
    public function __construct(
        private readonly MondayImport $import,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<string>|null  $only  the monday board ids to read (all mapped boards when null)
     * @param  list<string>|null  $items  read just these items (a webhook), not the changed ones
     * @return array<string, array<string, mixed>> monday board id => report
     */
    /** @param  bool  $reapply  take Monday's state even when the item's clock did not move (a group move may not bump it) */
    public function run(SocialConnection $connection, bool $full = false, ?array $only = null, ?array $items = null, ?MondayClient $client = null, bool $scan = false, bool $reapply = false, ?float $deadline = null): array
    {
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
        $reader = new MondayBoardReader($client ?? MondayClient::for($connection));
        $setup = (array) ($connection->setup ?? []);
        $state = (array) ($setup['monday'] ?? []);

        $as = $this->tenant->run($org, fn () => User::query()->find($state['as'] ?? null)
            ?? User::query()->where('is_active', true)->orderBy('created_at')->first());
        if ($as === null) {
            throw new \RuntimeException('Nobody in this organization to act as for the Monday sync.');
        }

        $boards = $this->tenant->run($org, function () use ($reader) {
            $this->adopt($reader);

            // Not an archived board: its Monday board is archived too and does not change, and
            // re-reading All Calls (52,684 items) every ten minutes would spend the Monday budget.
            // Smallest first: the big board (NW) last, so a run the lane stops still reached the others.
            return TaskBoard::query()->where('external_ref', 'like', 'monday:%')->whereNull('archived_at')->withCount('tasks')->orderBy('tasks_count')->get();
        });

        $reports = [];
        foreach ($boards as $board) {
            // Past the run's budget: stop between boards (each board's cursor is already kept), so one
            // long pass never holds the lane and the parity run behind it (desk 11-19: 2.5 h without parity).
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            $mondayId = substr((string) $board->external_ref, strlen('monday:'));
            if ($only !== null && ! in_array($mondayId, $only, true)) {
                continue;
            }
            $cursor = $state['cursors'][$mondayId] ?? null;
            if ($items !== null) {
                $ids = $items;
                $latest = null;
            } else {
                // Every item when asked (--full) or for a board never read; else what changed since its cursor:
                // Monday's own date filter on the 10-minute pass, a whole-board listing on the nightly --scan.
                ['ids' => $ids, 'latest' => $latest] = $full || $cursor === null
                    ? $reader->changedSince($mondayId, null)
                    : $reader->changedSince($mondayId, CarbonImmutable::parse($cursor), filtered: ! $scan);
            }
            try {
                $meta = $reader->board($mondayId);
            } catch (MondayRefused $e) {
                if (! str_starts_with($e->getMessage(), 'Monday shows no board')) {
                    throw $e; // the connection itself
                }
                report($e); // that one board is gone on Monday: the others still sync

                continue;
            }
            if ($ids === []) {
                // No item changed, but a board or group rename, a group added or reordered touches
                // no item's clock (desk 11-18): name, groups and members every pass.
                $this->import->run($org, ['people' => (array) ($state['people'] ?? []), 'users' => [],
                    'boards' => [$meta + ['target_board_id' => $board->id, 'items' => []]]], $as, refresh: true);
            } else {
                // A pass stopped part way through this board (its deadline) kept the items it had
                // done for this same cursor: the next run takes up the rest (desk 11-19: NW's cursor
                // sat at 16:44 for 5 h while every lane run restarted NW and was killed).
                $partial = (array) ($state['partial'][$mondayId] ?? []);
                if (isset($partial['done']) && ($partial['cursor'] ?? null) === $cursor && $items === null) {
                    $ids = array_values(array_diff($ids, (array) ($partial['done'] ?? [])));
                } else {
                    $partial = ['cursor' => $cursor, 'done' => []];
                }
                $stopped = false;
                // In chunks: a whole board (NW, 24,659 items) read and written in one go outlasts
                // the scheduler lane and holds one transaction for an hour (prod, 2026-11-13).
                foreach (array_chunk($ids, 200) as $chunk) {
                    if ($deadline !== null && microtime(true) > $deadline) {
                        $stopped = true;
                        break;
                    }
                    $report = $this->import->run($org, [
                        'people' => (array) ($state['people'] ?? []),
                        'users' => $reader->users(),
                        'boards' => [$meta + ['target_board_id' => $board->id, 'items' => $reader->items($mondayId, $chunk)]],
                    ], $as, refresh: true, reapply: $reapply);
                    foreach ($report['counts'] as $k => $v) {
                        $reports[$mondayId]['counts'][$k] = ($reports[$mondayId]['counts'][$k] ?? 0) + $v;
                    }
                    $reports[$mondayId] += $report;
                    if ($items === null) {
                        $partial['done'] = [...$partial['done'], ...array_map('strval', $chunk)];
                        $state['partial'][$mondayId] = $partial;
                        $this->save($org, $connection, $state);
                    }
                }
                if ($stopped) {
                    break; // the cursor stays: this board is not finished
                }
                unset($state['partial'][$mondayId]);
            }
            if ($latest !== null) {
                $state['cursors'][$mondayId] = $latest;
            }
            // Each board's cursor is kept as soon as it is read: a run stopped part way (the lane's
            // limit, a deploy) resumes from there instead of reading every board again.
            $this->save($org, $connection, $state);
        }

        $state['last'] = ['at' => now()->toIso8601String(), 'boards' => array_map(fn ($r) => $r['counts'], $reports)];
        $this->save($org, $connection, $state);

        return $reports;
    }

    private function save(Organization $org, SocialConnection $connection, array $state): void
    {
        $this->tenant->run($org, function () use ($connection, $state) {
            $connection->refresh();
            $setup = (array) ($connection->setup ?? []);
            // Cursors another writer moved on (the import, a parallel run) are not moved back.
            foreach ((array) ($setup['monday']['cursors'] ?? []) as $id => $at) {
                if (! isset($state['cursors'][$id]) || (string) $at > (string) $state['cursors'][$id]) {
                    $state['cursors'][$id] = $at;
                }
            }
            $connection->forceFill(['setup' => ['monday' => $state] + $setup])->save();
        });
    }

    /**
     * A board the import filled before boards carried their monday id: ask
     * Monday where one of its items lives, and stamp the board with it.
     */
    private function adopt(MondayBoardReader $reader): void
    {
        $unstamped = Task::query()->where('external_ref', 'like', 'monday:%')->whereNull('parent_task_id')
            ->whereIn('board_id', TaskBoard::query()->whereNull('external_ref')->select('id'))
            ->get(['board_id', 'external_ref'])->unique('board_id');
        foreach ($unstamped as $task) {
            $mondayBoard = $reader->boardOf(substr((string) $task->external_ref, strlen('monday:')));
            if ($mondayBoard !== null) {
                MondayImport::claim(TaskBoard::query()->findOrFail($task->board_id), 'monday:'.$mondayBoard);
            }
        }
    }
}
