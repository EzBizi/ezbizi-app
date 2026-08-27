<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use Illuminate\Auth\Access\Response;

final class TaskPolicy
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    public function view(User $user, Task $task): Response
    {
        return $this->access->viewDecision($user, $task->board, ViewScope::forUser($user));
    }

    public function create(User $user, TaskBoard $board): Response
    {
        return $this->forAction($user, $board, 'tasks.create', 'create tasks');
    }

    public function update(User $user, Task $task): Response
    {
        return $this->forAction($user, $task->board, 'tasks.edit', 'change this task');
    }

    public function delete(User $user, Task $task): Response
    {
        return $this->forAction($user, $task->board, 'tasks.delete', 'delete this task');
    }

    public function comment(User $user, Task $task): Response
    {
        return $this->forAction($user, $task->board, 'tasks.comment', 'comment on this task');
    }

    public function export(User $user, Task $task): Response
    {
        return $this->forAction($user, $task->board, 'tasks.export', 'export this task');
    }

    private function forAction(User $user, TaskBoard $board, string $permission, string $act): Response
    {
        if (! $user->hasPermission($permission)) {
            return Refusal::permission($act);
        }

        if ($permission !== 'tasks.export' && $user->organization?->isReadOnly()) {
            return Response::deny('This organization is read-only because its subscription has ended.');
        }

        return $this->access->viewDecision($user, $board, ViewScope::forUser($user));
    }
}
