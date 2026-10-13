<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** A task is as visible and as writable as the board it sits on. */
class TaskPolicy
{
    public function __construct(private readonly TaskBoardPolicy $boards) {}

    public function view(User $user, Task $task): Response
    {
        return $this->boards->view($user, $task->board);
    }

    public function update(User $user, Task $task): Response
    {
        return $this->boards->update($user, $task->board);
    }

    public function delete(User $user, Task $task): Response
    {
        return $this->boards->update($user, $task->board);
    }
}
