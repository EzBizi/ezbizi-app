<?php

namespace App\Console\Commands;

use App\Support\Tasks\TaskRecurrenceWorker;
use Illuminate\Console\Command;

final class CreateRecurringTasks extends Command
{
    protected $signature = 'ezbizi:create-recurring-tasks {--limit=500}';

    protected $description = 'Create the next due instance of every on_schedule recurring task series';

    public function handle(TaskRecurrenceWorker $worker): int
    {
        $count = $worker->onSchedule((int) $this->option('limit'));
        $this->info("Created {$count} recurring task instance(s).");

        return self::SUCCESS;
    }
}
