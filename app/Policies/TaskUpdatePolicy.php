<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class TaskUpdatePolicy
{
    public function __construct(private readonly TaskPolicy $tasks) {}

    public function view(User $user, TaskUpdate $update): Response
    {
        return $this->tasks->view($user, $update->task);
    }

    public function create(User $user, Task $task): Response
    {
        return $this->tasks->comment($user, $task);
    }

    public function update(User $user, TaskUpdate $update): Response
    {
        return $this->tasks->comment($user, $update->task);
    }

    public function archive(User $user, TaskUpdate $update): Response
    {
        return $this->tasks->comment($user, $update->task);
    }
}
