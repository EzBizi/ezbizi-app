<?php

namespace App\Support\Tasks;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which boards exist, and which ones a person sees and may write.
 *
 * Every company, franchise and person has exactly one board. `ensureFor` is
 * idempotent and is called from the model `created` events (AppServiceProvider)
 * and from `ezbizi:backfill-task-boards`.
 *
 * Visibility follows the CURRENT VIEW, never reach (AGENTS.md):
 *   franchise view    = my board + that franchise + its company
 *   company view      = my board + the company (and Sub Companies) + their franchises
 *   organization view = my board + every company and franchise I can reach
 */
class TaskBoards
{
    public const DEFAULT_GROUPS = [
        ['Open Tasks', '#579BFC'],
        ['Recurring Tasks', '#A25DDC'],
        ['Completed Tasks', '#00C875'],
        ['Company Files', '#FDAB3D'],
        ['Guidelines', '#784BD1'],
    ];

    /** key, name, colour, is_done — every new board starts with these. */
    public const BUILT_IN_STATUSES = [
        [Task::STATUS_NOT_STARTED, 'Not started', '#C4C4C4', false],
        [Task::STATUS_WORKING_ON_IT, 'Working on it', '#FDAB3D', false],
        [Task::STATUS_STUCK, 'Stuck', '#E2445C', false],
        [Task::STATUS_DONE, 'Done', '#00C875', true],
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    public function ensureFor(Company|Franchise|User $owner): TaskBoard
    {
        $organization = Organization::query()->withoutGlobalScopes()->findOrFail($owner->organization_id);

        return $this->tenant->run($organization, function () use ($owner) {
            [$kind, $column] = match (true) {
                $owner instanceof Company => [TaskBoard::KIND_COMPANY, 'company_id'],
                $owner instanceof Franchise => [TaskBoard::KIND_FRANCHISE, 'franchise_id'],
                default => [TaskBoard::KIND_USER, 'owner_user_id'],
            };

            $board = TaskBoard::query()->firstOrCreate(
                ['kind' => $kind, $column => $owner->getKey()],
                [
                    'organization_id' => $owner->organization_id,
                    'name' => $owner->name ?: 'Tasks',
                    'auto_key' => $kind.':'.$owner->getKey(),
                    'visibility' => TaskBoard::MAIN,
                ],
            );

            if ($board->wasRecentlyCreated) {
                $this->seed($board);
                if ($owner instanceof User && Schema::hasTable('task_board_members')) {
                    TaskBoardMember::query()->create([
                        'organization_id' => $board->organization_id,
                        'board_id' => $board->id,
                        'user_id' => $owner->getKey(),
                        'role' => TaskBoardMember::OWNER,
                    ]);
                }
            }

            return $board;
        });
    }

    /**
     * A custom board (inbox 0120) on a company, a franchise or the
     * organization (no place). The caller has already been checked against
     * the current view; they become its owner.
     */
    public function createCustom(User $creator, string $name, string $visibility, ?Company $company, ?Franchise $franchise): TaskBoard
    {
        return DB::transaction(function () use ($creator, $name, $visibility, $company, $franchise) {
            $board = TaskBoard::query()->create([
                'organization_id' => $creator->organization_id,
                'kind' => TaskBoard::KIND_CUSTOM,
                'company_id' => $franchise ? null : $company?->id,
                'franchise_id' => $franchise?->id,
                'created_by_user_id' => $creator->getKey(),
                'name' => $name,
                'visibility' => $visibility,
            ]);
            $this->seed($board);
            TaskBoardMember::query()->create([
                'organization_id' => $board->organization_id,
                'board_id' => $board->id,
                'user_id' => $creator->getKey(),
                'role' => TaskBoardMember::OWNER,
            ]);

            return $board;
        });
    }

    /** The default groups and the four built-in statuses, on a board that has none. */
    private function seed(TaskBoard $board): void
    {
        foreach (self::DEFAULT_GROUPS as $position => [$name, $colour]) {
            TaskGroup::query()->create([
                'organization_id' => $board->organization_id,
                'board_id' => $board->id,
                'name' => $name,
                'colour' => $colour,
                'position' => $position,
            ]);
        }
        // The 2026-10-13 board migration runs this before task_statuses
        // exists; the statuses migration seeds those boards itself.
        if (Schema::hasTable('task_statuses')) {
            foreach (self::BUILT_IN_STATUSES as $position => [$key, $name, $colour, $done]) {
                TaskStatus::query()->create([
                    'organization_id' => $board->organization_id,
                    'board_id' => $board->id,
                    'key' => $key,
                    'name' => $name,
                    'colour' => $colour,
                    'position' => $position,
                    'is_done' => $done,
                ]);
            }
        }
    }

    /** The person's role on the board: owner, member, viewer, or null. */
    public function roleOf(User $user, TaskBoard $board): ?string
    {
        if ($board->kind === TaskBoard::KIND_USER) {
            return $board->owner_user_id === $user->getKey() ? TaskBoardMember::OWNER : null;
        }

        return TaskBoardMember::query()->where('board_id', $board->getKey())
            ->where('user_id', $user->getKey())->value('role');
    }

    /** Owners and Administrators rename, delete, and manage members. */
    public function canManage(User $user, TaskBoard $board): bool
    {
        return $this->canView($user, $board)
            && ($user->isAdministrator() || $this->roleOf($user, $board) === TaskBoardMember::OWNER);
    }

    /**
     * Everyone whose reach covers the board's place; the whole organization
     * for an organization-level custom board. Who may be added as a member.
     *
     * @return Collection<int, User>
     */
    public function reach(TaskBoard $board): Collection
    {
        // ponytail: per-user reach check over the org's users; fine at ~100 users, push into SQL if orgs grow large.
        $place = $board->franchise ?? $board->company;
        $users = User::query()->orderBy('name')->get();

        if ($place === null) {
            return $board->kind === TaskBoard::KIND_CUSTOM ? $users->values() : collect();
        }

        return $users
            ->filter(fn (User $u) => $place instanceof Franchise ? $u->canActOnFranchise($place) : $u->canActOnCompany($place))
            ->values();
    }

    /**
     * People who may own a task on this board: the owner of a personal board,
     * the owners and members of a private board, otherwise everyone whose
     * reach covers the board's place.
     *
     * @return Collection<int, User>
     */
    public function assignable(TaskBoard $board): Collection
    {
        if ($board->kind === TaskBoard::KIND_USER) {
            return User::query()->whereKey($board->owner_user_id)->get(['id', 'name']);
        }

        if ($board->isPrivate()) {
            return User::query()->orderBy('name')
                ->whereIn('id', $board->members()->whereIn('role', [TaskBoardMember::OWNER, TaskBoardMember::MEMBER])->select('user_id'))
                ->get(['id', 'name']);
        }

        return $this->reach($board);
    }

    /** @return Builder<TaskBoard> */
    public function visibleTo(User $user): Builder
    {
        $mine = fn (Builder $q) => $q->where('kind', TaskBoard::KIND_USER)
            ->where('owner_user_id', $user->getKey());

        if (! $user->hasPermission('tasks.view')) {
            return TaskBoard::query()->where($mine);
        }

        [$companyIds, $franchiseIds] = $this->placesInView($user);

        return TaskBoard::query()->where(fn (Builder $q) => $q
            ->where($mine)
            ->orWhere(fn (Builder $c) => $c->where('kind', TaskBoard::KIND_COMPANY)->whereIn('company_id', $companyIds))
            ->orWhere(fn (Builder $f) => $f->where('kind', TaskBoard::KIND_FRANCHISE)->whereIn('franchise_id', $franchiseIds))
            // A custom board: its place is in view (or it is organization-level),
            // and it is main, or private and I am on it (or Administrator).
            ->orWhere(fn (Builder $c) => $c->where('kind', TaskBoard::KIND_CUSTOM)
                ->where(fn (Builder $p) => $p->whereIn('company_id', $companyIds)
                    ->orWhereIn('franchise_id', $franchiseIds)
                    ->orWhere(fn (Builder $o) => $o->whereNull('company_id')->whereNull('franchise_id')))
                ->when(! $user->isAdministrator(), fn (Builder $v) => $v->where(fn (Builder $m) => $m
                    ->where('visibility', TaskBoard::MAIN)
                    ->orWhereIn('id', TaskBoardMember::query()->where('user_id', $user->getKey())->select('board_id'))))));
    }

    public function canView(User $user, TaskBoard $board): bool
    {
        return $board->organization_id === $user->organization_id
            && $this->visibleTo($user)->whereKey($board->getKey())->exists();
    }

    /**
     * Your own board is always yours to write. A franchise or company board
     * needs tasks.edit AND write authority there from the current view —
     * a franchise view reads its company's board but cannot change it.
     */
    public function canEdit(User $user, TaskBoard $board): bool
    {
        if ($board->organization_id !== $user->organization_id) {
            return false;
        }

        if ($board->kind === TaskBoard::KIND_USER) {
            return $board->owner_user_id === $user->getKey();
        }

        if (! $user->hasPermission('tasks.edit') || ! $this->canView($user, $board)) {
            return false;
        }

        if ($board->kind === TaskBoard::KIND_CUSTOM) {
            // Members and owners write; a viewer reads. Anyone else on a main
            // board writes as they would that place's own board.
            $role = $this->roleOf($user, $board);
            if ($role !== null) {
                return $role !== TaskBoardMember::VIEWER;
            }
            if ($board->isPrivate() && ! $user->isAdministrator()) {
                return false;
            }
            [$type, $id] = match (true) {
                $board->franchise_id !== null => [TaskBoard::KIND_FRANCHISE, $board->franchise_id],
                $board->company_id !== null => [TaskBoard::KIND_COMPANY, $board->company_id],
                default => [Assignment::SCOPE_ORGANIZATION, null],
            };

            return OwnedScope::mayWrite($user, $board->organization_id, $type, $id);
        }

        return OwnedScope::mayWrite(
                $user,
                $board->organization_id,
                $board->kind,
                $board->kind === TaskBoard::KIND_COMPANY ? $board->company_id : $board->franchise_id,
            );
    }

    /** @return array{0: string[], 1: string[]} company ids, franchise ids */
    private function placesInView(User $user): array
    {
        $view = ViewScope::forUser($user);
        $franchiseIds = $view->franchiseIds();

        $companyIds = match ($view->type) {
            ViewScope::COMPANY => $view->companyIds(),
            ViewScope::FRANCHISE => array_filter([Franchise::query()->find($view->id)?->company_id]),
            default => $user->reachableCompanies()->pluck('id')
                ->merge($user->reachableFranchises()->pluck('company_id'))
                ->unique()->values()->all(),
        };

        return [array_values($companyIds), $franchiseIds];
    }

    /** The company a franchise board's tasks are filed under. */
    public static function companyIdOf(TaskBoard $board): ?string
    {
        return $board->company_id ?? ($board->franchise_id
            ? Franchise::query()->withTrashed()->find($board->franchise_id)?->company_id
            : null);
    }
}
