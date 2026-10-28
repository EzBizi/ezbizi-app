<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskLink;
use App\Models\User;
use Illuminate\Support\Collection;

/** Manual links between tasks (Jose, 2026-10-28). One row per pair, read both ways. */
class TaskLinks
{
    /** Ids of the tasks linked to this one, either direction. */
    public static function idsOf(Task $task): Collection
    {
        return TaskLink::query()->where('task_id', $task->id)->pluck('linked_task_id')
            ->merge(TaskLink::query()->where('linked_task_id', $task->id)->pluck('task_id'))
            ->unique()->values();
    }

    /** @return Collection<int, Task> */
    public static function of(Task $task): Collection
    {
        return Task::query()->whereIn('id', self::idsOf($task))->with('board')->get();
    }

    public static function exists(Task $a, Task $b): bool
    {
        return self::pair($a, $b)->exists();
    }

    /** Idempotent: null when already linked. The caller has checked who may. */
    public static function link(Task $a, Task $b, ?User $by): ?TaskLink
    {
        if ($a->id === $b->id || $a->organization_id !== $b->organization_id || self::exists($a, $b)) {
            return null;
        }

        return TaskLink::query()->create([
            'organization_id' => $a->organization_id,
            'task_id' => $a->id,
            'linked_task_id' => $b->id,
            'created_by_user_id' => $by?->getKey(),
        ]);
    }

    public static function unlink(Task $a, Task $b): void
    {
        self::pair($a, $b)->delete();
    }

    private static function pair(Task $a, Task $b)
    {
        return TaskLink::query()->where(fn ($q) => $q
            ->where(fn ($p) => $p->where('task_id', $a->id)->where('linked_task_id', $b->id))
            ->orWhere(fn ($p) => $p->where('task_id', $b->id)->where('linked_task_id', $a->id)));
    }
}
