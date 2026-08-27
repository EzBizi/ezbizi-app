<?php

namespace App\Console\Commands;

use App\Support\Tasks\TaskDueWorker;
use Illuminate\Console\Command;

final class DispatchTaskOverdue extends Command
{
    protected $signature = 'ezbizi:dispatch-task-overdue {--limit=500}';

    protected $description = 'Record newly overdue tasks and their delivery intents';

    public function handle(TaskDueWorker $worker): int
    {
        $count = $worker->overdue((int) $this->option('limit'));
        $this->info("Recorded {$count} overdue task occurrence(s).");

        return self::SUCCESS;
    }
}
