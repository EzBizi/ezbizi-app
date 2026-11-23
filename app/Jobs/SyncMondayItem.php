<?php

namespace App\Jobs;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondaySync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One Monday item, re-read after its webhook (MondayWebhookController). The
 * webhook's own values are never trusted: the item is read from Monday with
 * the connection's token and goes through the same upsert as the poll.
 */
final class SyncMondayItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 300];

    public function __construct(
        public readonly string $connectionId,
        public readonly string $boardId,
        public readonly string $itemId,
    ) {
        // Its own queue (desk 11-18): Monday's echoes of our pushes stalled missed calls on default.
        $this->onQueue('monday');
    }

    public function handle(MondaySync $sync): void
    {
        $connection = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Monday->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->find($this->connectionId);
        if ($connection === null) {
            return; // Disconnected between the webhook and this running.
        }

        // One item Monday told us about: its state is taken even when its clock did not move (a group move may not bump it, desk 11-18); the per-field merge keeps what changed here later.
        $sync->run($connection, only: [$this->boardId], items: [$this->itemId], reapply: true);
    }
}
