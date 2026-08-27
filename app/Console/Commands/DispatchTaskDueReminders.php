<?php

namespace App\Console\Commands;

use App\Support\Tasks\TaskDueWorker;
use Illuminate\Console\Command;

final class DispatchTaskDueReminders extends Command
{
    protected $signature = 'ezbizi:dispatch-task-due-reminders {--limit=500}';

    protected $description = 'Record due task reminders and their delivery intents';

    public function handle(TaskDueWorker $worker): int
    {
        $count = $worker->reminders((int) $this->option('limit'));
        $this->info("Recorded {$count} task due reminder(s).");

        return self::SUCCESS;
    }
}
