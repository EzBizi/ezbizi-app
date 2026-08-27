<?php

namespace App\Support\Tasks;

use App\Models\TaskView;
use RuntimeException;

final class TaskViewConflict extends RuntimeException
{
    public function __construct(public readonly TaskView $view)
    {
        parent::__construct('This saved view changed after you opened it.');
    }
}
