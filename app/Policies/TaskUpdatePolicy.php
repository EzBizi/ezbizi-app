<?php

namespace App\Policies;

use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * An update (and the files on it) is as visible and as writable as its task's
 * board. This is what GET attachments/{id} asks for a file on an update.
 */
class TaskUpdatePolicy
{
    public function __construct(private readonly TaskPolicy $tasks) {}

    public function view(User $user, TaskUpdate $update): Response
    {
        return $update->task ? $this->tasks->view($user, $update->task) : Response::denyAsNotFound();
    }

    public function update(User $user, TaskUpdate $update): Response
    {
        return $update->task ? $this->tasks->update($user, $update->task) : Response::denyAsNotFound();
    }
}
