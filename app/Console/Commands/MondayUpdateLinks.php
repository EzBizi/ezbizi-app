<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Support\Tasks\Monday\MondayBoardReader;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tasks\SensitiveTasks;
use Illuminate\Console\Command;

/**
 * One-off (row 38, Merav 11-23): imported Monday updates kept only the names
 * of their links (text_body). Re-reads each from Monday, 100 at a time, and
 * where Monday's HTML holds a link, puts it back as [name](url). An update
 * changed here since (its text no longer ends with Monday's) is left alone.
 * The rows it changes are written to storage/app/monday/ first.
 */
class MondayUpdateLinks extends Command
{
    protected $signature = 'ezbizi:monday-update-links {--dry-run}';

    protected $description = 'Put back the links of updates imported from Monday';

    public function handle(): int
    {
        $connection = SocialConnection::withoutGlobalScopes()->where('provider', 'monday')->whereNotNull('organization_id')->firstOrFail();
        $api = MondayClient::for($connection);
        $dir = storage_path('app/monday');
        @mkdir($dir, 0750, true);
        $backup = $dir.'/update-links-before-'.now()->format('YmdHis').'.jsonl';
        $counts = ['read' => 0, 'with links' => 0, 'changed' => 0, 'edited here, left' => 0];

        TaskUpdate::withoutGlobalScopes()->where('external_ref', 'like', 'monday:%')
            ->chunkById(100, function ($rows) use ($api, $backup, &$counts) {
                $byMonday = $rows->keyBy(fn (TaskUpdate $u) => (string) last(explode('.', substr((string) $u->external_ref, 7))));
                $found = $api->query('query($i:[ID!]){ updates(ids:$i, limit:100) { id body text_body } }', ['i' => $byMonday->keys()->all()])['updates'] ?? [];
                $counts['read'] += $rows->count();
                foreach ($found as $m) {
                    $u = $byMonday[(string) $m['id']] ?? null;
                    $new = MondayBoardReader::bodyOf($m);
                    if ($u === null || ! str_contains($new, '](http')) {
                        continue;
                    }
                    $counts['with links']++;
                    $task = Task::withoutGlobalScopes()->withTrashed()->find($u->task_id);
                    $current = $task?->sensitive ? SensitiveTasks::open((string) $u->body) : (string) $u->body;
                    $plain = trim((string) ($m['text_body'] ?? ''));
                    if ($plain === '' || ! str_ends_with($current, $plain)) {
                        $counts['edited here, left']++;

                        continue;
                    }
                    $body = substr($current, 0, strlen($current) - strlen($plain)).$new; // keeps "↳ " and "Name (on Monday): "
                    $counts['changed']++;
                    if ($this->option('dry-run')) {
                        continue;
                    }
                    file_put_contents($backup, json_encode(['id' => $u->id, 'body' => $u->body])."\n", FILE_APPEND);
                    $u->forceFill(['body' => $task?->sensitive ? SensitiveTasks::seal($body) : $body])->saveQuietly();
                }
            });

        foreach ($counts as $k => $v) {
            $this->line(($this->option('dry-run') ? '[dry run] ' : '')."$k: $v");
        }

        return self::SUCCESS;
    }
}
