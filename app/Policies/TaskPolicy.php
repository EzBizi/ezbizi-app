<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\SensitiveTasks;
use App\Support\Tasks\TaskBoards;
use Illuminate\Auth\Access\Response;

/** A task is as visible and as writable as the board it sits on. */
class TaskPolicy
{
    public function __construct(private readonly TaskBoardPolicy $boards) {}

    public function view(User $user, Task $task): Response
    {
        // An API key (MCP) or the AI never reaches a sensitive task (Jose, 2026-11-12).
        if (SensitiveTasks::hiddenFrom($user, $task)) {
            return Response::denyAsNotFound();
        }

        // A deleted (custom) board takes its tasks out of sight with it.
        return $task->board ? $this->boards->view($user, $task->board) : Response::denyAsNotFound();
    }

    /**
     * On a board in "assignee" mode (Jose, 2026-11-09, monday's NW board) a
     * task is written only by its owners, its maker, and whoever manages the
     * board; everyone else may still add tasks and read them all.
     */
    public function update(User $user, Task $task): Response
    {
        if (! $task->board || SensitiveTasks::hiddenFrom($user, $task)) {
            return Response::denyAsNotFound();
        }
        $board = $this->boards->update($user, $task->board);
        if ($board->denied() || $task->board->edit_mode !== 'assignee'
            || app(TaskBoards::class)->canManage($user, $task->board)
            || $task->created_by_user_id === $user->getKey()
            || $task->owners()->where('user_id', $user->getKey())->exists()) {
            return $board;
        }

        return Response::deny('On this board only the people a task is assigned to can change it.');
    }

    public function delete(User $user, Task $task): Response
    {
        return $this->update($user, $task);
    }
}
