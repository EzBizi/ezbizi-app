<?php

namespace App\Support\Tasks;

use RuntimeException;

final class TaskOrderConflict extends RuntimeException
{
    public function __construct(public readonly array $currentGroupVersions)
    {
        parent::__construct('This task order changed after you opened it. Refresh the group and try again.');
    }
}
