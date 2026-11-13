<?php

namespace App\Support\Tasks\Monday;

use App\Models\MondayOutboxEntry;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Support\Social\SocialProvider;

/**
 * Task Board → Monday, the recording half (Jose, 2026-11-13: two-way while
 * both are in use). TaskWriter tells it which fields of a task changed; a
 * task on a board imported from Monday, in an organization with a live Monday
 * connection, gets a pending outbox row naming those fields. MondayPush sends
 * it. Nothing is recorded while the Monday sync itself is writing (suppress),
 * so Monday's own changes never echo back.
 */
final class MondayOutbox
{
    private static int $suppressed = 0;

    /** @var array<string, bool> organization id => has a live Monday connection */
    private array $connected = [];

    /** Run $fn with recording off: the import and the sync write what Monday already has. */
    public static function suppress(callable $fn): mixed
    {
        self::$suppressed++;
        try {
            return $fn();
        } finally {
            self::$suppressed--;
        }
    }

    /** @param  list<string>  $kinds  create, title, status, due, owners, group, values:<column id> */
    public function record(Task $task, array $kinds, ?TaskUpdate $update = null): void
    {
        if (self::$suppressed > 0 || ($kinds === [] && $update === null) || ! $this->linked($task)) {
            return;
        }
        if ($update !== null) {
            if (! str_starts_with((string) $update->external_ref, 'monday:')) {
                MondayOutboxEntry::query()->create([
                    'organization_id' => $task->organization_id, 'task_id' => $task->id, 'task_update_id' => $update->id,
                    'kinds' => ['update'], 'changed_at' => now(), 'available_at' => now(),
                ]);
            }

            return;
        }
        $pending = MondayOutboxEntry::query()->where('task_id', $task->id)->whereNull('task_update_id')
            ->where('state', MondayOutboxEntry::PENDING)->first();
        if ($pending !== null) {
            $pending->forceFill(['kinds' => array_values(array_unique([...(array) $pending->kinds, ...$kinds])), 'changed_at' => now()])->save();

            return;
        }
        MondayOutboxEntry::query()->create([
            'organization_id' => $task->organization_id, 'task_id' => $task->id,
            'kinds' => array_values(array_unique($kinds)), 'changed_at' => now(), 'available_at' => now(),
        ]);
    }

    /** On a board that answers for a Monday board, with Monday connected for the organization. */
    private function linked(Task $task): bool
    {
        $board = $task->relationLoaded('board') ? $task->board : $task->board()->withoutGlobalScopes()->first();
        if ($board === null || ! str_starts_with((string) $board->external_ref, 'monday:')) {
            return false;
        }

        // By the task's own organization: a sweep or command may write tasks with no tenant in context.
        return $this->connected[$task->organization_id] ??= SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $task->organization_id)
            ->where('provider', SocialProvider::Monday->value)->where('status', SocialConnection::STATUS_CONNECTED)->exists();
    }
}
