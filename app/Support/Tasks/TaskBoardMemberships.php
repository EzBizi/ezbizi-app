<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskBoardMemberships
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskParticipantEligibility $participants,
        private readonly AuditLogger $audit,
    ) {}

    public function add(User $actor, TaskBoard $board, User $candidate, ViewScope $scope, BoardMemberRole $role): TaskBoardMember
    {
        return DB::transaction(function () use ($actor, $board, $candidate, $scope, $role): TaskBoardMember {
            [$lockedBoard, $memberships] = $this->lockManagedBoard($actor, $board, $scope);
            $this->participants->forMembership($candidate, $lockedBoard)->authorize();

            $membership = $memberships->firstWhere('user_id', $candidate->id) ?? new TaskBoardMember;
            $membership->forceFill([
                'organization_id' => $lockedBoard->organization_id,
                'board_id' => $lockedBoard->id,
                'user_id' => $candidate->id,
                'role' => $role->value,
                'is_active' => true,
                'archived_at' => null,
            ])->save();

            $this->audit->log('task_board.member_saved', metadata: [
                'board_id' => $lockedBoard->id,
                'membership_id' => $membership->id,
                'user_id' => $candidate->id,
                'role' => $role->value,
            ], actor: $actor);

            return $membership->fresh('user');
        });
    }

    public function changeRole(
        User $actor,
        TaskBoard $board,
        TaskBoardMember $membership,
        ViewScope $scope,
        BoardMemberRole $role,
    ): TaskBoardMember {
        return DB::transaction(function () use ($actor, $board, $membership, $scope, $role): TaskBoardMember {
            [$lockedBoard, $memberships] = $this->lockManagedBoard($actor, $board, $scope);
            $lockedMembership = $memberships->firstWhere('id', $membership->id);
            abort_if($lockedMembership === null, 404);

            if ($lockedMembership->role === BoardMemberRole::Owner && $role !== BoardMemberRole::Owner) {
                $this->assertAnotherEligibleOwner($lockedBoard, $memberships, $lockedMembership->id);
            }

            if ($role === BoardMemberRole::Owner) {
                $candidate = User::query()->findOrFail($lockedMembership->user_id);
                $this->participants->forMembership($candidate, $lockedBoard)->authorize();
            }

            $lockedMembership->forceFill(['role' => $role->value])->save();
            $this->audit->log('task_board.member_role_changed', metadata: [
                'board_id' => $lockedBoard->id,
                'membership_id' => $lockedMembership->id,
                'user_id' => $lockedMembership->user_id,
                'role' => $role->value,
            ], actor: $actor);

            return $lockedMembership->fresh('user');
        });
    }

    public function revoke(User $actor, TaskBoard $board, TaskBoardMember $membership, ViewScope $scope): TaskBoardMember
    {
        return DB::transaction(function () use ($actor, $board, $membership, $scope): TaskBoardMember {
            [$lockedBoard, $memberships] = $this->lockManagedBoard($actor, $board, $scope);
            $lockedMembership = $memberships->firstWhere('id', $membership->id);
            abort_if($lockedMembership === null, 404);

            if ($lockedMembership->is_active && $lockedMembership->role === BoardMemberRole::Owner) {
                $this->assertAnotherEligibleOwner($lockedBoard, $memberships, $lockedMembership->id);
            }

            $lockedMembership->forceFill(['is_active' => false, 'archived_at' => now()])->save();
            $this->audit->log('task_board.member_revoked', metadata: [
                'board_id' => $lockedBoard->id,
                'membership_id' => $lockedMembership->id,
                'user_id' => $lockedMembership->user_id,
            ], actor: $actor);

            return $lockedMembership->fresh('user');
        });
    }

    /** @return array{TaskBoard, Collection<int, TaskBoardMember>} */
    private function lockManagedBoard(User $actor, TaskBoard $board, ViewScope $scope): array
    {
        $lockedBoard = TaskBoard::query()->lockForUpdate()->findOrFail($board->id);
        $memberships = TaskBoardMember::query()
            ->where('board_id', $lockedBoard->id)
            ->lockForUpdate()
            ->get();
        $this->access->manageDecision($actor, $lockedBoard, $scope)->authorize();

        return [$lockedBoard, $memberships];
    }

    /** @param Collection<int, TaskBoardMember> $memberships */
    private function assertAnotherEligibleOwner(TaskBoard $board, Collection $memberships, string $excludedId): void
    {
        $eligibleOwnerExists = $memberships
            ->where('is_active', true)
            ->where('role', BoardMemberRole::Owner)
            ->where('id', '!==', $excludedId)
            ->contains(function (TaskBoardMember $membership) use ($board): bool {
                $owner = User::query()->find($membership->user_id);

                return $owner !== null && $this->participants->forMembership($owner, $board)->allowed();
            });

        if (! $eligibleOwnerExists) {
            throw ValidationException::withMessages([
                'membership' => 'Promote another eligible board owner before removing or demoting this owner.',
            ]);
        }
    }
}
