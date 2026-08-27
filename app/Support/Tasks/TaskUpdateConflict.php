<?php

namespace App\Support\Tasks;

use RuntimeException;

final class TaskUpdateConflict extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('This update changed after you opened it. Review the latest version and try again.');
    }
}
