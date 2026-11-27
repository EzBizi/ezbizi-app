<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayBoardReader;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tasks\Monday\MondayImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every Monday board onto the Task Board, read live through the Monday
 * connection (Jose, 2026-11-13: "start the full migration … as we have it on
 * Monday"). The map (docs/agents/monday-board-map-2026-11-13.json) says where
 * each board lands; the board's groups, labels and colours, columns, views
 * and members come with it, then its items in chunks, each through
 * MondayImport (idempotent on monday:<id>). --dry-run rolls every chunk back
 * and prints what it would do, per board.
 *
 * After the real run the 10-minute sync keeps every mapped board up to date,
 * and the outbox writes changes here back to Monday.
 */
class MondayImportBoards extends Command
{
    protected $signature = 'ezbizi:monday-import-boards {--map= : JSON file, monday board id => target} {--board=* : only these monday board ids}
        {--connection= : the Monday connection} {--as= : EzBizi email of the person the import acts as (default: who connected)}
        {--dry-run : roll every chunk back} {--chunk=100 : items read and imported at a time} {--limit= : at most this many items per board (a sample)}
        {--reapply : take the Monday fields again on tasks already here (after a mapping fix), unless changed here since}';

    protected $description = 'Import Monday boards onto the Task Board, as they are on Monday';

    public function handle(MondayImport $import, TenantContext $tenant): int
    {
        $map = json_decode((string) file_get_contents((string) $this->option('map')), true, flags: JSON_THROW_ON_ERROR);
        $connection = SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::Monday->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))->firstOrFail();
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
        $state = (array) ($connection->setup['monday'] ?? []);
        $as = $tenant->run($org, fn () => $this->option('as')
            ? User::query()->where('email', $this->option('as'))->firstOrFail()
            : User::query()->findOrFail($state['as'] ?? null));
        $reader = new MondayBoardReader(MondayClient::for($connection));
        $users = $reader->users();
        $only = array_map('strval', (array) $this->option('board'));
        $dry = (bool) $this->option('dry-run');
        $failed = false;

        foreach ($map as $boardId => $target) {
            $boardId = (string) $boardId;
            if (str_starts_with($boardId, '_') || ($only !== [] && ! in_array($boardId, $only, true))) {
                continue;
            }
            if (($target['target'] ?? '') === 'skip') {
                $this->line("== monday board {$boardId}: skipped ({$target['why']})");

                continue;
            }
            try {
                $meta = $reader->board($boardId) + array_intersect_key($target, array_flip(['owner_email', 'scope', 'visibility', 'archived']));
                if (($target['target'] ?? '') !== 'personal') {
                    unset($meta['owner_email']);
                }
                ['ids' => $ids, 'latest' => $latest] = $reader->changedSince($boardId, null);
                if ($this->option('limit') !== null) {
                    $ids = array_slice($ids, 0, (int) $this->option('limit'));
                }
                $total = ['counts' => [], 'unmatched' => [], 'owners_dropped' => [], 'not_carried' => []];
                foreach (array_chunk($ids, max(1, (int) $this->option('chunk'))) ?: [[]] as $chunk) {
                    $report = $import->run($org, [
                        'people' => (array) ($state['people'] ?? []), 'users' => $users,
                        'boards' => [$meta + ['items' => $chunk === [] ? [] : $reader->items($boardId, $chunk)]],
                    ], $as, dry: $dry, refresh: (bool) $this->option('reapply'), reapply: (bool) $this->option('reapply'));
                    foreach ($report['counts'] as $k => $v) {
                        $total['counts'][$k] = ($total['counts'][$k] ?? 0) + $v;
                    }
                    $total['unmatched'] = array_values(array_unique([...$total['unmatched'], ...$report['unmatched']]));
                    foreach ($report['owners_dropped'] as $name => $n) {
                        $total['owners_dropped'][$name] = ($total['owners_dropped'][$name] ?? 0) + $n;
                    }
                    array_push($total['not_carried'], ...$report['not_carried']);
                }
                if ($dry) {
                    // Every chunk was rolled back, so each one made the board again.
                    $total['counts']['boards made'] = min(1, (int) ($total['counts']['boards made'] ?? 0));
                }
                $this->line("== {$meta['name']} ({$boardId}): ".count($ids).' items'.($dry ? ' — DRY RUN, rolled back' : ''));
                TasksImportMonday::print($this, $total);
                if (! $dry && $latest !== null && $this->option('limit') === null) {
                    // The 10-minute sync starts from what the import read, not from nothing: a new
                    // board with no cursor is read whole on every run (NW outlasted the lane, 11-13).
                    $tenant->run($org, function () use ($connection, $boardId, $latest) {
                        $connection->refresh();
                        $setup = (array) ($connection->setup ?? []);
                        if ((string) ($setup['monday']['cursors'][$boardId] ?? '') < $latest) {
                            $setup['monday']['cursors'][$boardId] = $latest;
                            $connection->forceFill(['setup' => $setup])->save();
                        }
                    });
                }
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("== monday board {$boardId}: ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
