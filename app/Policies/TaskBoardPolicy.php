<?php

namespace App\Policies;

use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Tasks\TaskBoards;
use Illuminate\Auth\Access\Response;

/** Your own board is always yours; a team board follows tasks.* and the current view. */
class TaskBoardPolicy
{
    public function __construct(private readonly TaskBoards $boards) {}

    public function view(User $user, TaskBoard $board): Response
    {
        return $this->boards->canView($user, $board)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, TaskBoard $board): Response
    {
        if (! $this->boards->canView($user, $board)) {
            return Response::denyAsNotFound();
        }

        return $this->boards->canEdit($user, $board)
            ? Response::allow()
            : Response::deny('You can see this board, but you cannot change it from your current view.');
    }
}
