<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskColumnValue;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Tasks\JobTitle;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Row 41 (Jose 11-23): every task, open and done, on every board whose title
 * still carries a job number ("600025660 Albert", "Jade 400595628",
 * "Job #800020972 - Kirstin Deimel"): the job linked, EzBizi Job # and
 * Workiz Job ID filled where empty, the title reduced to the name. The writer
 * queues each change for Monday's paced push. A number that is no job is
 * left and counted. Rows it changes are written to storage/app/ first.
 */
class TasksJobTitles extends Command
{
    protected $signature = 'ezbizi:tasks-job-titles {--org=} {--dry-run}';

    protected $description = 'Take job numbers out of task titles into the job columns';

    public function handle(TenantContext $tenant): int
    {
        // History, not events: no rule fires on the backfill (the 11-19 lesson).
        app()->instance(EventEmitter::class, new class extends EventEmitter
        {
            public function __construct() {}

            public function emitAfterCommit(string $eventKey, ?Model $subject = null, array $payload = []): void {}
        });
        $writer = app()->make(TaskWriter::class);
        $writer->quiet = true;
        $backup = storage_path('app/task-job-titles-before-'.now()->format('YmdHis').'.jsonl');
        $counts = ['matched' => 0, 'fixed' => 0, 'no such job' => 0, 'linked elsewhere, left' => 0];
        $unresolved = [];

        $orgs = $this->option('org') ? [Organization::withoutGlobalScopes()->findOrFail($this->option('org'))] : Organization::withoutGlobalScopes()->get();
        foreach ($orgs as $org) {
            $tenant->run($org, function () use ($writer, $backup, &$counts, &$unresolved) {
                $actor = User::query()->oldest()->first();
                JobTitle::preload(); // one read of the Workiz numbers, not a scan per task
                Task::query()->with('board')->whereNotNull('board_id')->chunkById(500, function ($tasks) use ($writer, $backup, $actor, &$counts, &$unresolved) {
                    foreach ($tasks as $task) {
                        $split = JobTitle::split((string) $task->title);
                        if ($split === null || $task->board === null) {
                            continue;
                        }
                        $counts['matched']++;
                        $job = JobTitle::job($split['number']);
                        if ($job === null) {
                            $counts['no such job']++;
                            $unresolved[$task->board->name] = ($unresolved[$task->board->name] ?? 0) + 1;

                            continue;
                        }
                        if ($task->about_id !== null && ($task->about_type !== 'job' || $task->about_id !== $job->id)) {
                            $counts['linked elsewhere, left']++;

                            continue;
                        }
                        $filled = TaskColumnValue::query()->where('task_id', $task->id)->where('value_text', '!=', '')->pluck('column_id')->all();
                        $values = array_diff_key($writer->jobValues($task, 'job', $job->id), array_flip($filled));
                        $title = JobTitle::clean($split, $job, (string) $task->title);
                        $counts['fixed']++;
                        if ($this->option('dry-run')) {
                            continue;
                        }
                        file_put_contents($backup, json_encode(['id' => $task->id, 'title' => $task->title, 'about_type' => $task->about_type, 'about_id' => $task->about_id])."\n", FILE_APPEND);
                        $writer->update($task, ['title' => mb_substr($title, 0, 255), 'about_type' => 'job', 'about_id' => $job->id] + ($values ? ['values' => $values] : []), $actor);
                    }
                });
                JobTitle::forget();
            });
        }

        foreach ($counts as $k => $v) {
            $this->line(($this->option('dry-run') ? '[dry run] ' : '')."$k: $v");
        }
        arsort($unresolved);
        foreach ($unresolved as $board => $n) {
            $this->line("  no such job on $board: $n");
        }

        return self::SUCCESS;
    }
}
