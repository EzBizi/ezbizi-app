<?php

namespace App\Console\Commands;

use App\Support\Tasks\TaskNotificationIntentDispatcher;
use Illuminate\Console\Command;

final class DispatchTaskNotificationIntents extends Command
{
    protected $signature = 'ezbizi:dispatch-task-notification-intents {--limit=100}';

    protected $description = 'Deliver due task notification intents';

    public function handle(TaskNotificationIntentDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchPending((int) $this->option('limit'));
        $this->info("Delivered {$count} task notification intent(s).");

        return self::SUCCESS;
    }
}
