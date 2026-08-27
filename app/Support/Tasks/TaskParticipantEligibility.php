<?php

namespace App\Support\Tasks;

use App\Models\Company;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Modules\ModuleAvailability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

final class TaskParticipantEligibility
{
    public function __construct(
        private readonly ModuleAvailability $availability,
        private readonly ScopeReach $reach,
    ) {}

    public function forMembership(User $candidate, TaskBoard $board): Response
    {
        if ($candidate->organization_id !== $board->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $candidate->is_active) {
            return Response::deny('That person is inactive and cannot participate in task work.');
        }

        if (! $this->availability->isAvailable('tasks', $candidate->organization)) {
            return Response::deny('Tasks are not available for this organization.');
        }

        if (! $candidate->hasPermission('tasks.view')) {
            return Refusal::permission('participate in Tasks');
        }

        return TaskPlace::reachedBy($candidate, $board, $this->reach)
            ? Response::allow()
            : Response::deny('That person is not assigned at the place where this board is filed.');
    }

    public function forTaskParticipation(User $candidate, TaskBoard $board): Response
    {
        $eligible = $this->forMembership($candidate, $board);

        if ($eligible->denied() || $board->visibility !== BoardVisibility::Private) {
            return $eligible;
        }

        return TaskBoardMember::query()
            ->active()
            ->where('board_id', $board->getKey())
            ->where('user_id', $candidate->getKey())
            ->exists()
                ? Response::allow()
                : Response::deny('Add that person to this private board before assigning or mentioning them.');
    }

    public function constrainForMembership(Builder $query, TaskBoard $board): Builder
    {
        if (! $this->availability->isAvailable('tasks', $board->organization)) {
            return $query->whereRaw('0 = 1');
        }

        $companyIds = [];
        if ($board->company_id !== null) {
            $company = Company::query()->find($board->company_id);
            $companyIds = array_values(array_filter([$company?->id, $company?->parent_company_id]));
        }

        return $query
            ->where('is_active', true)
            ->whereHas('role', fn (Builder $role) => $role
                ->where('is_administrator', true)
                ->orWhereHas('permissions', fn (Builder $permission) => $permission->where('key', 'tasks.view')))
            ->whereHas('assignments', function (Builder $assignment) use ($board, $companyIds): void {
                $assignment->where('assigned_organization_id', $board->organization_id);
                if ($companyIds !== []) {
                    $assignment->orWhereIn('assigned_company_id', $companyIds);
                }
                if ($board->franchise_id !== null) {
                    $assignment->orWhere('assigned_franchise_id', $board->franchise_id);
                }
            });
    }

    public function constrainForTaskParticipation(Builder $query, TaskBoard $board): Builder
    {
        $query = $this->constrainForMembership($query, $board);

        return $board->visibility === BoardVisibility::Private
            ? $query->whereHas('taskBoardMemberships', fn (Builder $membership) => $membership
                ->active()->where('board_id', $board->id))
            : $query;
    }
}
