<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
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
        is_dir($dir) || mkdir($dir, 0750, true);
        $backup = $dir.'/update-links-before-'.now()->format('YmdHis').'.jsonl';
        $counts = ['read' => 0, 'with links' => 0, 'changed' => 0, 'edited here, left' => 0, 'mentions filled' => 0];
        // Who a Monday user is here (Dieuve 11-23: her Monday @mentions were never mapped): the
        // connection's people map, else the same email, else the same name. No bells: history.
        $map = array_change_key_case((array) ($connection->setup['monday']['people'] ?? []));
        $here = User::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->get();
        $who = [];
        foreach ($api->query('{ users(limit:1000, kind:all) { id name email } }')['users'] ?? [] as $m) {
            $email = mb_strtolower((string) ($m['email'] ?? ''));
            $email = mb_strtolower((string) ($map[$email] ?? $email));
            $who[(string) $m['id']] = $here->first(fn (User $u) => mb_strtolower((string) $u->email) === $email)?->id
                ?? $here->first(fn (User $u) => mb_strtolower(trim((string) $u->name)) === mb_strtolower(trim((string) ($m['name'] ?? ''))))?->id;
        }

        TaskUpdate::withoutGlobalScopes()->where('external_ref', 'like', 'monday:%')
            ->chunkById(100, function ($rows) use ($api, $backup, $who, &$counts) {
                $byMonday = $rows->keyBy(fn (TaskUpdate $u) => (string) last(explode('.', substr((string) $u->external_ref, 7))));
                $found = $api->query('query($i:[ID!]){ updates(ids:$i, limit:100) { id body text_body } }', ['i' => $byMonday->keys()->all()])['updates'] ?? [];
                // Desk 11-23: updates(ids:) leaves out replies (and some older updates),
                // so the rest are read through their items, replies included.
                $missing = $byMonday->except(array_map(fn ($m) => (string) $m['id'], $found));
                $items = Task::withoutGlobalScopes()->withTrashed()->whereKey($missing->pluck('task_id')->unique())->pluck('external_ref')
                    ->map(fn ($r) => (string) preg_replace('/\D.*/', '', substr((string) $r, 7)))->filter()->unique()->values()->all();
                if ($items !== []) {
                    foreach ($api->query('query($i:[ID!]){ items(ids:$i, limit:100) { updates(limit:100) { id body text_body replies { id body text_body } } } }', ['i' => $items])['items'] ?? [] as $item) {
                        foreach ($item['updates'] ?? [] as $up) {
                            foreach ([$up, ...($up['replies'] ?? [])] as $m) {
                                if ($missing->has((string) $m['id'])) {
                                    $found[] = $m;
                                }
                            }
                        }
                    }
                }
                $counts['read'] += $rows->count();
                $counts['found on Monday'] = ($counts['found on Monday'] ?? 0) + count($found);
                foreach ($found as $m) {
                    $u = $byMonday[(string) $m['id']] ?? null;
                    $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $who[$id] ?? null, MondayBoardReader::mentionIds($m)))));
                    if ($u !== null && $ids !== [] && empty($u->mentions)) {
                        $counts['mentions filled']++;
                        if (! $this->option('dry-run')) {
                            file_put_contents($backup, json_encode(['id' => $u->id, 'mentions' => $u->mentions])."\n", FILE_APPEND);
                            $u->forceFill(['mentions' => $ids])->saveQuietly();
                            $counts['mentioned rows'] = ($counts['mentioned rows'] ?? 0) + self::listMentioned($u, $ids);
                        }
                    }
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

    /**
     * A past @mention (last 30 days) listed under "Mentioned" (desk 11-23, Jose's
     * tab was empty): the row the live sync now writes, already read, no push or
     * email. Not the author; only who can see the task; never twice.
     *
     * @param  list<string>  $ids
     */
    public static function listMentioned(TaskUpdate $update, array $ids): int
    {
        if ($update->created_at === null || $update->created_at->lt(now()->subDays(30))) {
            return 0;
        }
        $org = \App\Models\Organization::withoutGlobalScopes()->find($update->organization_id);

        return $org === null ? 0 : app(\App\Support\Tenancy\TenantContext::class)->run($org, function () use ($update, $ids) {
            $task = Task::query()->find($update->task_id);
            if ($task === null) {
                return 0;
            }
            $title = ($update->user()->value('name') ?? 'Somebody on Monday').' mentioned you';
            $n = 0;
            foreach (User::query()->whereKey(array_diff($ids, [$update->user_id]))->get() as $user) {
                if (! \Illuminate\Support\Facades\Gate::forUser($user)->check('view', $task)
                    || \App\Models\Notification::query()->where('user_id', $user->id)->where('subject_id', $task->id)->where('kind', 'task.mentioned')->where('title', $title)->exists()) {
                    continue;
                }
                \App\Models\Notification::insert([[
                    'id' => (string) \Illuminate\Support\Str::uuid7(), 'organization_id' => $task->organization_id, 'user_id' => $user->id,
                    'source' => 'mention', 'kind' => 'task.mentioned', 'title' => $title,
                    'body' => $task->sensitive ? SensitiveTasks::NOTICE : \Illuminate\Support\Str::limit(trim((string) SensitiveTasks::open($update->body)), 160),
                    'urgent' => false, 'subject_type' => Task::class, 'subject_id' => $task->id, 'automation_id' => null, 'event_id' => null,
                    'read_at' => now(), 'created_at' => $update->created_at,
                ]]);
                $n++;
            }

            return $n;
        });
    }
}
