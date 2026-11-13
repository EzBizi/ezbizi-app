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
    public function run(SocialConnection $connection, bool $full = false, ?array $only = null, ?array $items = null, ?MondayClient $client = null): array
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
            return TaskBoard::query()->where('external_ref', 'like', 'monday:%')->whereNull('archived_at')->get();
        });

        $reports = [];
        foreach ($boards as $board) {
            $mondayId = substr((string) $board->external_ref, strlen('monday:'));
            if ($only !== null && ! in_array($mondayId, $only, true)) {
                continue;
            }
            $cursor = $state['cursors'][$mondayId] ?? null;
            if ($items !== null) {
                $ids = $items;
                $latest = null;
            } else {
                ['ids' => $ids, 'latest' => $latest] = $reader->changedSince($mondayId, $full || $cursor === null ? null : CarbonImmutable::parse($cursor));
            }
            if ($ids !== []) {
                $meta = $reader->board($mondayId);
                $reports[$mondayId] = $this->import->run($org, [
                    'people' => (array) ($state['people'] ?? []),
                    'users' => $reader->users(),
                    'boards' => [$meta + ['target_board_id' => $board->id, 'items' => $reader->items($mondayId, $ids)]],
                ], $as, refresh: true);
            }
            if ($latest !== null) {
                $state['cursors'][$mondayId] = $latest;
            }
        }

        $state['last'] = ['at' => now()->toIso8601String(), 'boards' => array_map(fn ($r) => $r['counts'], $reports)];
        $this->tenant->run($org, function () use ($connection, $state) {
            $connection->refresh();
            $connection->forceFill(['setup' => ['monday' => $state] + (array) ($connection->setup ?? [])])->save();
        });

        return $reports;
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
