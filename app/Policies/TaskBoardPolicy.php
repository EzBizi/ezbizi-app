<?php

namespace App\Policies;

use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use Illuminate\Auth\Access\Response;

final class TaskBoardPolicy
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    public function viewAny(User $user): Response
    {
        return $this->access->directoryDecision($user, ViewScope::forUser($user));
    }

    public function view(User $user, TaskBoard $board): Response
    {
        return $this->access->viewDecision($user, $board, ViewScope::forUser($user));
    }

    public function create(User $user, ?ViewScope $scope = null): Response
    {
        return $this->access->createDecision($user, $scope ?? ViewScope::forUser($user));
    }

    public function update(User $user, TaskBoard $board): Response
    {
        return $this->access->manageDecision($user, $board, ViewScope::forUser($user));
    }

    public function delete(User $user, TaskBoard $board): Response
    {
        return $this->access->manageDecision($user, $board, ViewScope::forUser($user));
    }
}
