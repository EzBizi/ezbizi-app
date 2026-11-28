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
        if (! $update->task) {
            return Response::denyAsNotFound();
        }
        // Files on an update are its author's (audit 11-27): another editor of the board may not add or remove them.
        if ((string) $update->user_id !== (string) $user->id && ! $user->isAdministrator()) {
            return Response::deny('Only the person who wrote this update, or an Administrator, can change its files.');
        }

        return $this->tasks->update($user, $update->task);
    }
}
