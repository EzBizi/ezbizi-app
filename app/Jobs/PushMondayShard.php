<?php

namespace App\Jobs;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayPush;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One of N parallel Monday senders (desk 11-18: one sender did ~13 rows a
 * minute). Each takes the outbox rows of its own share of tasks, so a task's
 * rows stay in order. On the "monday-push" queue, one worker per shard.
 */
final class PushMondayShard implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 290;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $shard, public readonly int $shards, public readonly int $limit = 60)
    {
        $this->onQueue('monday-push');
    }

    public function uniqueId(): string
    {
        return $this->shard.'/'.$this->shards;
    }

    public function handle(MondayPush $push): void
    {
        foreach (SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::Monday->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->get() as $connection) {
            rescue(fn () => $push->run($connection, $this->limit, shard: $this->shard, shards: $this->shards));
        }
    }
}
