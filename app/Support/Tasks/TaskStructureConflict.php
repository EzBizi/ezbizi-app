<?php

namespace App\Support\Tasks;

use RuntimeException;

final class TaskStructureConflict extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The board structure changed. Refresh it before trying again.');
    }
}
