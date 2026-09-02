<?php

namespace App\Console\Commands;

use App\Support\Tasks\TaskRecurrenceWorker;
use Illuminate\Console\Command;

final class CreateRecurringTasks extends Command
{
    protected $signature = 'ezbizi:create-recurring-tasks {--limit=500}';

    protected $description = 'Advance every on_schedule series and relay every pending after_completion instance';

    public function handle(TaskRecurrenceWorker $worker): int
    {
        $limit = (int) $this->option('limit');
        $scheduled = $worker->onSchedule($limit);
        $relayed = $worker->relayPendingCompletions($limit);
        $this->info("Created {$scheduled} on_schedule and {$relayed} after_completion recurring task instance(s).");

        return self::SUCCESS;
    }
}
