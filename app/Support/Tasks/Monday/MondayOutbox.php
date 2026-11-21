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

    private static int $backfill = 0;

    /** Run $fn with its rows queued as backfill: live work is pushed first (desk 11-20). */
    public static function asBackfill(callable $fn): mixed
    {
        self::$backfill++;
        try {
            return $fn();
        } finally {
            self::$backfill--;
        }
    }

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
        // Jose, 2026-11-16: "why won't they be copied in monday from ezbizi, if everything else
        // syncs". A task made here on a Monday board — by a person or a rule — is made on Monday
        // (create), and from then on goes both ways. A task that is not on Monday and was never
        // sent there (a branch board's own work from before its Monday board was merged in) is
        // not pushed after the fact, so Monday is not flooded with the past.
        if (! str_starts_with((string) $task->external_ref, 'monday:') && ! in_array('create', $kinds, true)
            && ! MondayOutboxEntry::query()->where('task_id', $task->id)->whereJsonContains('kinds', 'create')->exists()) {
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
            'kinds' => array_values(array_unique($kinds)), 'changed_at' => now(), 'available_at' => now(), 'backfill' => self::$backfill > 0,
        ]);
    }

    /** On a board that answers for a Monday board, with Monday connected for the organization. */
    private function linked(Task $task): bool
    {
        $board = $task->relationLoaded('board') ? $task->board : $task->board()->withoutGlobalScopes()->first();
        // An archived board is a read-only mirror of an archived Monday board (All Calls, Toula;
        // Jose via desk 11-13): nothing on it is written back.
        if ($board === null || ! str_starts_with((string) $board->external_ref, 'monday:') || $board->archived_at !== null) {
            return false;
        }

        // By the task's own organization: a sweep or command may write tasks with no tenant in context.
        return $this->connected[$task->organization_id] ??= SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $task->organization_id)
            ->where('provider', SocialProvider::Monday->value)->where('status', SocialConnection::STATUS_CONNECTED)->exists();
    }
}
