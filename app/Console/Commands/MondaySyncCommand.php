<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayRefused;
use App\Support\Tasks\Monday\MondaySync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The Monday → Task Board poll (every 10 minutes; docs/agents/monday-sync-
 * design-2026-11-12.md). Each connected Monday account, one at a time, reads
 * the items changed since its cursor on every board imported from Monday.
 * Read only: nothing is written to Monday.
 *
 * --full reads every item (the first run after Connect, and a nightly pass
 * that catches what a cursor cannot, such as a reply that did not touch its
 * item). --people=<file.json> stores a {monday email: EzBizi email} map on the
 * connection first, for people whose two emails and names differ.
 */
class MondaySyncCommand extends Command
{
    protected $signature = 'ezbizi:monday-sync {--connection= : one Monday connection id} {--full : read every item, not just the changed ones} {--scan : list every item to find the changed ones (a subitem-only change), not just the Monday date filter} {--people= : JSON file of monday email => EzBizi email} {--max-seconds= : stop between boards once this long has passed}';

    protected $description = 'Read Monday boards imported onto the Task Board and bring their changes over';

    public function handle(MondaySync $sync, TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Monday->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $people = null;
        if ($file = $this->option('people')) {
            $people = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        }

        $failed = false;
        $started = microtime(true);
        foreach ($connections as $connection) {
            $save = fn (array $fields) => $tenant->run(
                Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id),
                fn () => $connection->forceFill($fields)->save(),
            );
            if (is_array($people)) {
                $setup = (array) ($connection->setup ?? []);
                $setup['monday']['people'] = $people + (array) ($setup['monday']['people'] ?? []);
                $save(['setup' => $setup]);
            }
            try {
                $reports = $sync->run($connection, full: (bool) $this->option('full'), scan: (bool) $this->option('scan'),
                    deadline: $this->option('max-seconds') ? $started + (int) $this->option('max-seconds') : null);
            } catch (MondayRefused $e) {
                $save([
                    'status' => SocialConnection::STATUS_BROKEN,
                    'status_reason' => $e->getMessage(),
                    'last_checked_at' => now(),
                ]);
                $this->error($connection->display_name.': '.$e->getMessage());
                $failed = true;

                continue;
            } catch (\Throwable $e) {
                report($e);
                $this->error($connection->display_name.': '.$e->getMessage());
                $failed = true;

                continue;
            }
            foreach ($reports as $board => $report) {
                $this->line("== {$connection->display_name} · monday board {$board}");
                TasksImportMonday::print($this, $report);
            }
            if ($reports === []) {
                $this->line("{$connection->display_name}: nothing changed");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
