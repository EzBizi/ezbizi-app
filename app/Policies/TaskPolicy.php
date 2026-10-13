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
        // A deleted (custom) board takes its tasks out of sight with it.
        return $task->board ? $this->boards->view($user, $task->board) : Response::denyAsNotFound();
    }

    public function update(User $user, Task $task): Response
    {
        return $task->board ? $this->boards->update($user, $task->board) : Response::denyAsNotFound();
    }

    public function delete(User $user, Task $task): Response
    {
        return $this->update($user, $task);
    }
}
