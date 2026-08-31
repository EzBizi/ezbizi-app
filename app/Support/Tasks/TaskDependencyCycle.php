<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskDependency;

/**
 * Would a proposed "blocks" edge close a loop (Tasks Phase 2 design §9,
 * task-4 brief)?
 *
 * Display only — this class decides whether an edge may be DRAWN, never
 * whether a task may be COMPLETED. Nothing here reads `completed_at`.
 *
 * Called from inside `TaskDependencyWriter::add`'s transaction, after the
 * board row has been locked with `lockForUpdate()`. That lock — not this
 * class — is what stops a concurrent insert from slipping a cycle past the
 * check: two requests racing to add edges that are each individually
 * cycle-free serialize on the board row, so the second one sees the first
 * one's edge before it walks the graph.
 */
final class TaskDependencyCycle
{
    /**
     * How many "blocks" hops to follow before giving up.
     *
     * Not a realistic board size — it is a circuit breaker. A board with a
     * genuine dependency chain this deep is not something Phase 2 was built
     * for, and refusing beats a request that never returns.
     */
    public const MAX_DEPTH = 500;

    /**
     * True if adding "$blockerTaskId blocks $blockedTaskId" would make the
     * two wait on each other, directly or through others in between.
     *
     * The new edge points blocker -> blocked. That closes a loop exactly
     * when $blockedTaskId already leads back to $blockerTaskId through
     * existing edges — so the walk starts at $blockedTaskId and follows
     * "u blocks v" edges outward (v = blocked_task_id, from u = blocker_task_id),
     * looking for $blockerTaskId at each layer.
     */
    public function wouldCreate(TaskBoard $board, string $blockerTaskId, string $blockedTaskId): bool
    {
        if ($blockerTaskId === $blockedTaskId) {
            return true;
        }

        $frontier = [$blockedTaskId];
        $visited = [$blockedTaskId => true];

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if ($frontier === []) {
                return false;
            }

            $next = TaskDependency::query()
                ->where('board_id', $board->id)
                ->whereIn('blocker_task_id', $frontier)
                ->pluck('blocked_task_id')
                ->unique()
                ->values()
                ->all();

            if (in_array($blockerTaskId, $next, true)) {
                return true;
            }

            $frontier = array_values(array_filter($next, fn (string $id) => ! isset($visited[$id])));
            foreach ($frontier as $id) {
                $visited[$id] = true;
            }
        }

        // The bound was reached without resolving either way. A board whose
        // dependency graph is this deep refuses rather than walking forever.
        return true;
    }
}
