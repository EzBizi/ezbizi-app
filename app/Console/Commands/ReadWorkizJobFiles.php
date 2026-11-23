<?php

namespace App\Console\Commands;

use App\Jobs\RereadWorkizRecord;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Console\Command;

/**
 * A done job's photos, read once per version of the job. Only a single read
 * carries a job's files (WorkizV2::job) and the webhooks fire at creation,
 * before any photo exists, so on 11-20 Reports found 333 of 891 done jobs
 * never read. Queues a single read for each done job of the last 30 days whose
 * files were not read at its current utcUpdatedAt.
 */
final class ReadWorkizJobFiles extends Command
{
    protected $signature = 'ezbizi:read-workiz-job-files {--limit=60 : reads queued per branch per run}';

    protected $description = 'Queue a single Workiz read for done jobs whose files were not read at their current version';

    public function handle(): int
    {
        $queued = 0;

        foreach (SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::Workiz->value)->where('status', 'connected')->get() as $connection) {
            if (! WorkizV2::uses($connection)) {
                continue;
            }

            // ponytail: a fixed per-branch cap keeps a backlog inside the key's rate; raise it if a branch falls behind.
            $ids = WorkizJob::query()->withoutGlobalScopes()
                ->where('franchise_id', $connection->scope_id)
                ->whereIn('status', ['Done', 'done pending approval', 'done', 'Done Pending Approval'])
                ->where('scheduled_for', '>=', now()->subDays(30)) // the job's own date; synced_at moves on every list read
                ->whereRaw("json_unquote(json_extract(payload, '$.v2.id')) is not null")
                ->whereRaw("coalesce(json_unquote(json_extract(payload, '$.AttachmentsReadAt')), '') <> coalesce(json_unquote(json_extract(payload, '$.v2.utc_updated_at')), '-')")
                ->orderByDesc('scheduled_for')->limit((int) $this->option('limit'))
                ->pluck('payload')->map(fn ($p) => $p['v2']['id'] ?? null)->filter();

            foreach ($ids as $id) {
                RereadWorkizRecord::dispatch($connection->getKey(), 'job', (string) $id);
                $queued++;
            }
        }

        $this->info("Queued {$queued} file reads.");

        return self::SUCCESS;
    }
}
