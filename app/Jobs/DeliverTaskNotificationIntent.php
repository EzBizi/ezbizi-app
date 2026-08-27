<?php

namespace App\Jobs;

use App\Support\Tasks\TaskNotificationIntentDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverTaskNotificationIntent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $intentId) {}

    public function uniqueId(): string
    {
        return $this->intentId;
    }

    public function handle(TaskNotificationIntentDispatcher $dispatcher): void
    {
        $dispatcher->dispatchOne($this->intentId);
    }
}
