<?php

namespace App\Support\Tasks;

use RuntimeException;

final class TaskConflict extends RuntimeException
{
    public function __construct(
        public readonly int $currentVersion,
        public readonly array $current,
        public readonly array $conflictingFields,
    ) {
        parent::__construct('This task changed after you opened it. Review the current values and try again.');
    }
}
