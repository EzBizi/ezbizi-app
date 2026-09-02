<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\ModuleAvailability;
use App\Support\Scoping\ViewScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Board-level access decisions for Tasks (spec §4, §9).
 *
 * ── `viewDecision()` denies two different ways, and it matters which ──
 *
 * `viewDecision()` refuses a board through either of two paths, and they are
 * not interchangeable — mixing them up is what cost three sessions real time
 * verifying this method today, each stopping at the first deny path it found
 * and reporting a different, contradictory answer.
 *
 *   - 403, from `baseDecision()`'s own `Response::deny()` /
 *     `Refusal::permission()` branches: the Tasks module is unavailable for
 *     the organization, or the actor lacks `tasks.view`. Both answer
 *     IDENTICALLY for every board id, including one that does not exist, so
 *     this path discloses nothing about any particular board.
 *
 *   - 404, from `Response::denyAsNotFound()`, raised from FOUR call sites:
 *     `baseDecision()`'s cross-organization check, and `viewDecision()`'s own
 *     archived-board check, out-of-scope check (`TaskPlace::coveredBy`), and
 *     `privateMembershipDecision()`'s non-member check. THIS is the
 *     disclosing path, and all four must stay indistinguishable — in BOTH
 *     status code and response body — from a board id that never existed.
 *     Telling one of these apart from "not found" would tell an actor
 *     whether a specific id is a real board in someone else's organization,
 *     out of their scope, or on someone's private membership list.
 *
 * The trap: `baseDecision()` is not "the 403 branch". Its FIRST check
 * (cross-organization) returns a 404, not a 403 — so it is wrong to assume
 * everything inside `baseDecision()` maps to 403, and equally wrong to assume
 * every `denyAsNotFound()` call lives in `viewDecision()` itself. Read the
 * actual branch taken, not the method name it lives in.
 *
 * `manageDecision()`, `boardActions()`, and `manageArchivedDecision()` all
 * route through the same `baseDecision()` and inherit this split unchanged.
 */
final class TaskBoardAccess
{
    public function __construct(
        private readonly ModuleAvailability $availability,
        private readonly ScopeReach $reach,
        private readonly TaskParticipantEligibility $participants,
        private readonly AuditLogger $audit,
    ) {}

    public function constrainVisible(Builder $query, User $user, ViewScope $scope): Builder
    {
        if (! $this->moduleAndPermission($user)) {
            return $query->whereRaw('0 = 1');
        }

        $companyIds = $scope->type === ViewScope::FRANCHISE
            ? []
            : array_values(array_intersect(
                $user->reachableCompanies()->modelKeys(),
                $scope->isOrganization() ? $user->reachableCompanies()->modelKeys() : $scope->companyIds(),
            ));
        $franchiseIds = array_values(array_intersect(
            $user->reachableFranchises()->modelKeys(),
            $scope->franchiseIds(),
        ));
        $organization = $scope->isOrganization()
            && $this->reach->covers($user, 'organization', $user->organization_id);

        $query->where(function (Builder $places) use ($companyIds, $franchiseIds, $organization): void {
            $hasBranch = false;

            if ($organization) {
                $places->where(fn (Builder $organization) => $organization
                    ->whereNull('company_id')->whereNull('franchise_id'));
                $hasBranch = true;
            }

            if ($companyIds !== []) {
                $method = $hasBranch ? 'orWhere' : 'where';
                $places->{$method}(fn (Builder $companies) => $companies
                    ->whereIn('company_id', $companyIds)->whereNull('franchise_id'));
                $hasBranch = true;
            }

            if ($franchiseIds !== []) {
                $method = $hasBranch ? 'orWhereIn' : 'whereIn';
                $places->{$method}('franchise_id', $franchiseIds);
                $hasBranch = true;
            }

            if (! $hasBranch) {
                $places->whereRaw('0 = 1');
            }
        });

        return $query->where(function (Builder $visibility) use ($user): void {
            $visibility->where('visibility', BoardVisibility::Scope->value)
                ->orWhereHas('members', fn (Builder $members) => $members
                    ->active()
                    ->where('user_id', $user->getKey()));
        });
    }

    /** @return list<string> */
    public function directoryActions(User $user, ViewScope $scope): array
    {
        $canCreate = $this->createDecision($user, $scope)->allowed();

        return array_values(array_filter([
            $canCreate ? 'create_board' : null,
            $canCreate ? 'create_folder' : null,
            $canCreate ? 'view_archived' : null,
            $this->canBrowseRecoverable($user, $scope) ? 'recover_private_boards' : null,
        ]));
    }

    /** @return list<string> */
    public function boardActions(User $user, TaskBoard $board, ViewScope $scope): array
    {
        if ($board->archived_at !== null) {
            return $this->manageArchivedDecision($user, $board, $scope)->allowed()
                ? ['restore']
                : [];
        }

        if ($this->viewDecision($user, $board, $scope)->denied()) {
            return [];
        }

        $manage = $this->manageDecision($user, $board, $scope)->allowed();
        $canCreateHere = $this->createDecision($user, $scope)->allowed();
        $place = TaskPlace::fromView($scope);
        $samePlace = $board->company_id === $place['company_id']
            && $board->franchise_id === $place['franchise_id'];
        $canExport = $user->hasPermission('tasks.export');

        return array_values(array_filter([
            $manage ? 'update' : null,
            $manage ? 'archive' : null,
            $manage && $canCreateHere ? 'duplicate' : null,
            $manage && $canCreateHere && $samePlace ? 'duplicate_with_tasks' : null,
            $manage ? 'manage_members' : null,
            $manage ? 'manage_structure' : null,
            $manage && $user->hasPermission('tasks.create') ? 'import' : null,
            $canExport ? 'export' : null,
            $manage && $canExport ? 'archive_manifest' : null,
        ]));
    }

    public function canBrowseRecoverable(User $user, ViewScope $scope): bool
    {
        return $user->is_active
            && $user->isAdministrator()
            && ! $user->organization?->isReadOnly()
            && $this->directoryDecision($user, $scope)->allowed();
    }

    /** @return Collection<int,TaskBoard> */
    public function recoverableAtCurrentView(User $user, ViewScope $scope): Collection
    {
        abort_unless($this->canBrowseRecoverable($user, $scope), 403,
            'Only an active organization Administrator can recover orphaned private boards.');

        $place = TaskPlace::fromView($scope);

        return TaskBoard::query()->active()
            ->where('visibility', BoardVisibility::Private->value)
            ->where('company_id', $place['company_id'])
            ->where('franchise_id', $place['franchise_id'])
            ->with(['members.user.role.permissions', 'members.user.assignments'])
            ->orderBy('position')->get()
            ->filter(fn (TaskBoard $board) => $this->recoveryDecision($user, $board)->allowed())
            ->values();
    }

    public function recoveryDecision(User $administrator, TaskBoard $board): Response
    {
        if (! $administrator->is_active
            || ! $administrator->isAdministrator()
            || $administrator->organization_id !== $board->organization_id) {
            return Response::deny('Only an active organization Administrator can recover an orphaned private board.');
        }

        if ($board->visibility !== BoardVisibility::Private) {
            return Response::deny('Owner recovery is only for an orphaned private board.');
        }

        if (! $this->availability->isAvailable('tasks', $administrator->organization)
            || $administrator->organization?->isReadOnly()) {
            return Response::deny('Tasks owner recovery is unavailable while the module or subscription is inactive.');
        }

        $memberships = $board->relationLoaded('members')
            ? $board->members
            : $board->members()->with('user.role.permissions', 'user.assignments')->get();
        $eligibleOwnerExists = $memberships
            ->where('is_active', true)
            ->where('role', BoardMemberRole::Owner)
            ->contains(fn (TaskBoardMember $membership): bool =>
                $membership->user !== null
                && $this->participants->forMembership($membership->user, $board)->allowed());

        return $eligibleOwnerExists
            ? Response::deny('This board still has an eligible owner and does not need recovery.')
            : Response::allow();
    }

    public function recoveryAtCurrentViewDecision(
        User $administrator,
        TaskBoard $board,
        ViewScope $scope,
    ): Response {
        if (! $this->canBrowseRecoverable($administrator, $scope)) {
            return Response::deny('Only an active organization Administrator can recover orphaned private boards.');
        }
        $place = TaskPlace::fromView($scope);
        if ($board->archived_at !== null
            || $board->organization_id !== $place['organization_id']
            || $board->company_id !== $place['company_id']
            || $board->franchise_id !== $place['franchise_id']) {
            return Response::denyAsNotFound();
        }

        $recovery = $this->recoveryDecision($administrator, $board);

        return $recovery->allowed() ? $recovery : Response::denyAsNotFound();
    }

    public function viewDecision(User $user, TaskBoard $board, ViewScope $scope): Response
    {
        $base = $this->baseDecision($user, $board);

        if ($base->denied()) {
            return $base;
        }

        if ($board->archived_at !== null) {
            return Response::denyAsNotFound();
        }

        if (! TaskPlace::coveredBy($user, $board, $scope, $this->reach)) {
            return Response::denyAsNotFound();
        }

        return $this->privateMembershipDecision($user, $board);
    }

    public function backgroundViewDecision(User $user, TaskBoard $board): Response
    {
        $base = $this->baseDecision($user, $board);

        if ($base->denied()) {
            return $base;
        }

        if ($board->archived_at !== null) {
            return Response::denyAsNotFound();
        }

        if (! TaskPlace::reachedBy($user, $board, $this->reach)) {
            return Response::deny('This board is outside where you are assigned.');
        }

        return $this->privateMembershipDecision($user, $board);
    }

    public function constrainBackgroundVisible(Builder $query, User $user): Builder
    {
        if (! $this->moduleAndPermission($user)) {
            return $query->whereRaw('0 = 1');
        }

        $companyIds = $user->reachableCompanies()->modelKeys();
        $franchiseIds = $user->reachableFranchises()->modelKeys();
        $organization = $this->reach->covers($user, 'organization', $user->organization_id);

        $query->where(function (Builder $places) use ($companyIds, $franchiseIds, $organization): void {
            $hasBranch = false;
            if ($organization) {
                $places->where(fn (Builder $organization) => $organization
                    ->whereNull('company_id')->whereNull('franchise_id'));
                $hasBranch = true;
            }
            if ($companyIds !== []) {
                $method = $hasBranch ? 'orWhere' : 'where';
                $places->{$method}(fn (Builder $companies) => $companies
                    ->whereIn('company_id', $companyIds)->whereNull('franchise_id'));
                $hasBranch = true;
            }
            if ($franchiseIds !== []) {
                $method = $hasBranch ? 'orWhereIn' : 'whereIn';
                $places->{$method}('franchise_id', $franchiseIds);
                $hasBranch = true;
            }
            if (! $hasBranch) {
                $places->whereRaw('0 = 1');
            }
        });

        return $query->where(function (Builder $visibility) use ($user): void {
            $visibility->where('visibility', BoardVisibility::Scope->value)
                ->orWhereHas('members', fn (Builder $members) => $members
                    ->active()->where('user_id', $user->id));
        });
    }

    public function createDecision(User $user, ViewScope $scope): Response
    {
        if (! $this->availability->isAvailable('tasks', $user->organization)) {
            return Response::deny('Tasks are not available for this organization.');
        }

        if (! $user->hasPermission('tasks.manage')) {
            return Refusal::permission('create task boards');
        }

        if ($user->organization?->isReadOnly()) {
            return Response::deny('This organization is read-only because its subscription has ended.');
        }

        $place = TaskPlace::fromView($scope);
        $covered = match ($scope->type) {
            ViewScope::COMPANY => $this->reach->covers($user, 'company', $place['company_id']),
            ViewScope::FRANCHISE => $this->reach->covers($user, 'franchise', $place['franchise_id']),
            default => $this->reach->covers($user, 'organization', $place['organization_id']),
        };

        return $covered
            ? Response::allow()
            : Response::deny('You are not assigned at the exact active view, so you cannot file a board there.');
    }

    public function directoryDecision(User $user, ViewScope $scope): Response
    {
        if (! $this->availability->isAvailable('tasks', $user->organization)) {
            return Response::deny('Tasks are not available for this organization.');
        }

        if (! $user->hasPermission('tasks.view')) {
            return Refusal::permission('view Tasks');
        }

        $place = TaskPlace::fromView($scope);
        $covered = match ($scope->type) {
            ViewScope::COMPANY => $this->reach->covers($user, 'company', $place['company_id']),
            ViewScope::FRANCHISE => $this->reach->covers($user, 'franchise', $place['franchise_id']),
            default => $this->reach->covers($user, 'organization', $place['organization_id']),
        };

        return $covered
            ? Response::allow()
            : Response::deny('Your active view is outside where you are assigned.');
    }

    public function manageDecision(User $user, TaskBoard $board, ViewScope $scope): Response
    {
        $view = $this->viewDecision($user, $board, $scope);

        if ($view->denied()) {
            return $view;
        }

        return $this->ownerManagementDecision($user, $board);
    }

    public function manageAtFiledPlaceDecision(User $user, TaskBoard $board): Response
    {
        $base = $this->baseDecision($user, $board);
        if ($base->denied()) {
            return $base;
        }
        if ($board->archived_at !== null || ! TaskPlace::reachedBy($user, $board, $this->reach)) {
            return Response::denyAsNotFound();
        }
        $membership = $this->privateMembershipDecision($user, $board);
        if ($membership->denied()) {
            return $membership;
        }

        return $this->ownerManagementDecision($user, $board);
    }

    public function manageArchivedDecision(User $user, TaskBoard $board, ViewScope $scope): Response
    {
        $base = $this->baseDecision($user, $board);

        if ($base->denied()) {
            return $base;
        }

        if (! TaskPlace::coveredBy($user, $board, $scope, $this->reach)) {
            return Response::denyAsNotFound();
        }

        $membership = $this->privateMembershipDecision($user, $board);
        if ($membership->denied()) {
            return $membership;
        }

        return $this->ownerManagementDecision($user, $board);
    }

    private function ownerManagementDecision(User $user, TaskBoard $board): Response
    {

        if (! $user->hasPermission('tasks.manage')) {
            return Refusal::permission('manage this task board');
        }

        if ($user->organization?->isReadOnly()) {
            return Response::deny('This organization is read-only because its subscription has ended.');
        }

        return TaskBoardMember::query()
            ->active()
            ->where('board_id', $board->getKey())
            ->where('user_id', $user->getKey())
            ->where('role', BoardMemberRole::Owner->value)
            ->exists()
                ? Response::allow()
                : Response::deny('Only an active board owner with Tasks management permission can administer this board.');
    }

    /** @return array{board_id:string,owner_user_id:string} */
    public function recoverOrphanedOwner(User $administrator, TaskBoard $board, User $newOwner): array
    {
        if (! $administrator->is_active
            || ! $administrator->isAdministrator()
            || $administrator->organization_id !== $board->organization_id) {
            throw new AuthorizationException('Only an active organization Administrator can recover an orphaned private board.');
        }

        if ($administrator->getKey() === $newOwner->getKey()) {
            throw new AuthorizationException('The recovering Administrator must nominate another eligible owner.');
        }

        if ($board->visibility !== BoardVisibility::Private) {
            throw new AuthorizationException('Owner recovery is only for an orphaned private board.');
        }

        if (! $this->availability->isAvailable('tasks', $administrator->organization)
            || $administrator->organization?->isReadOnly()) {
            throw new AuthorizationException('Tasks owner recovery is unavailable while the module or subscription is inactive.');
        }

        return DB::transaction(function () use ($administrator, $board, $newOwner): array {
            $lockedBoard = TaskBoard::query()->lockForUpdate()->findOrFail($board->getKey());
            $memberships = TaskBoardMember::query()
                ->where('board_id', $lockedBoard->getKey())
                ->lockForUpdate()
                ->get();

            $eligibleOwnerExists = $memberships
                ->where('is_active', true)
                ->where('role', BoardMemberRole::Owner)
                ->contains(function (TaskBoardMember $membership) use ($lockedBoard): bool {
                    $owner = User::query()->find($membership->user_id);

                    return $owner !== null
                        && $this->participants->forMembership($owner, $lockedBoard)->allowed();
                });

            if ($eligibleOwnerExists) {
                throw new AuthorizationException('This board still has an eligible owner and does not need recovery.');
            }

            $eligible = $this->participants->forMembership($newOwner, $lockedBoard);
            if ($eligible->denied()) {
                throw new AuthorizationException((string) $eligible->message());
            }

            $membership = $memberships->firstWhere('user_id', $newOwner->getKey())
                ?? new TaskBoardMember;
            $membership->forceFill([
                'organization_id' => $lockedBoard->organization_id,
                'board_id' => $lockedBoard->getKey(),
                'user_id' => $newOwner->getKey(),
                'role' => BoardMemberRole::Owner->value,
                'is_active' => true,
                'archived_at' => null,
            ])->save();

            $auditScope = TaskPlace::auditScope($lockedBoard);
            $this->audit->log('task_board.owner_recovered', target: $lockedBoard, changes: [
                'before' => ['eligible_owner_user_id' => null],
                'after' => ['eligible_owner_user_id' => $newOwner->getKey()],
            ], metadata: [
                'board_id' => $lockedBoard->getKey(),
                'owner_user_id' => $newOwner->getKey(),
            ], actor: $administrator, scopeType: $auditScope['type'], scopeId: $auditScope['id']);

            return ['board_id' => $lockedBoard->getKey(), 'owner_user_id' => $newOwner->getKey()];
        });
    }

    private function baseDecision(User $user, TaskBoard $board): Response
    {
        if ($board->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $this->availability->isAvailable('tasks', $user->organization)) {
            return Response::deny('Tasks are not available for this organization.');
        }

        return $user->hasPermission('tasks.view')
            ? Response::allow()
            : Refusal::permission('view Tasks');
    }

    private function moduleAndPermission(User $user): bool
    {
        return $this->availability->isAvailable('tasks', $user->organization)
            && $user->hasPermission('tasks.view');
    }

    private function privateMembershipDecision(User $user, TaskBoard $board): Response
    {
        if ($board->visibility !== BoardVisibility::Private) {
            return Response::allow();
        }

        return TaskBoardMember::query()
            ->active()
            ->where('board_id', $board->getKey())
            ->where('user_id', $user->getKey())
            ->exists()
                ? Response::allow()
                : Response::denyAsNotFound();
    }
}
