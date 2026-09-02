<?php

namespace App\Support\Tasks;

/**
 * A task's recurrence declaration (Tasks Phase 2 design, task-5 brief).
 *
 * Immutable value object. Produced and consumed only through
 * TaskRecurrenceCodec — nothing constructs this from raw, unvalidated
 * input directly. This is a declaration only: Task 6 builds the worker that
 * reads it and acts on it.
 */
final class TaskRecurrence
{
    public function __construct(
        public readonly string $mode,
        public readonly int $every,
        public readonly string $unit,
        public readonly ?string $until = null,
        public readonly ?int $count = null,
        /**
         * The date recurrence was declared on this task (YYYY-MM-DD),
         * stamped server-side by TaskWriter::setRecurrence — never
         * accepted from client input. Floors TaskRecurrenceWorker's
         * on_schedule walk so declaring recurrence on a task with a
         * historical start_on/due_on cannot backfill every occurrence
         * between then and now (Tasks Phase 2 whole-branch review,
         * finding 1). Null only for a recurrence parsed before this field
         * existed; the worker treats that as "no floor".
         */
        public readonly ?string $declaredOn = null,
    ) {}
}
