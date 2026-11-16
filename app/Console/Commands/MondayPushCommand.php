<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayPush;
use App\Support\Tasks\Monday\MondayRefused;
use Illuminate\Console\Command;

/**
 * Task Board → Monday (Jose, 2026-11-13: two-way while both are in use).
 * Sends each connected Monday account's pending outbox rows: name, status,
 * due date, assignees, columns, group, new items and updates. Every minute.
 */
class MondayPushCommand extends Command
{
    protected $signature = 'ezbizi:monday-push {--connection= : one Monday connection id} {--limit=50}';

    protected $description = 'Write Task Board changes back to their Monday items';

    public function handle(MondayPush $push): int
    {
        $failed = false;
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Monday->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();
        foreach ($connections as $connection) {
            try {
                $counts = $push->run($connection, (int) $this->option('limit'));
                $this->line($connection->display_name.': '.json_encode($counts));
            } catch (MondayRefused $e) {
                $this->error($connection->display_name.': '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
