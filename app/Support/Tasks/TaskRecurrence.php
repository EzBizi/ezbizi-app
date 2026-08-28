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
    ) {}
}
