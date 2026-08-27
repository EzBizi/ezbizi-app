<?php

namespace App\Support\Tasks;

use RuntimeException;

final class TaskRecordLinkConflict extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('This task changed after you opened it. Refresh its links and try again.');
    }
}
