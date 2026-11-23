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
 * Every top-level company, its franchises and every person has exactly one
 * board; a Sub Company and its franchises have none (Jose, 2026-11-06), see
 * `hasPlaceBoard`. Older Sub Company boards are hidden, not deleted. `ensureFor` is
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
        ['Recurring Tasks', '#00A9B5'],
        ['Completed Tasks', '#00C875'],
        ['Company Files', '#FDAB3D'],
        ['Guidelines', '#7F5347'],
    ];

    /** key, name, colour, is_done — every new board starts with these. */
    public const BUILT_IN_STATUSES = [
        [Task::STATUS_NOT_STARTED, 'Not started', '#C4C4C4', false],
        [Task::STATUS_WORKING_ON_IT, 'Working on it', '#FDAB3D', false],
        [Task::STATUS_STUCK, 'Stuck', '#E2445C', false],
        [Task::STATUS_DONE, 'Done', '#00C875', true],
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * A company keeps a board only when it is top-level; a franchise only when
     * its company is (Jose, 2026-11-06: A-1 Chimney and A-1 Chimney - Texas
     * do, Sub Company Ace Fireplace does not).
     */
    public static function hasPlaceBoard(Company|Franchise $place): bool
    {
        $company = $place instanceof Company ? $place
            : Company::query()->withoutGlobalScopes()->find($place->company_id);

        return $company !== null && $company->parent_company_id === null;
    }

    /** Hides the boards of Sub Companies and their franchises. */
    private function withoutSubCompanyBoards(Builder $query): Builder
    {
        $subCompanies = Company::query()->whereNotNull('parent_company_id')->select('id');

        return $query
            ->whereNot(fn (Builder $c) => $c->where('kind', TaskBoard::KIND_COMPANY)->whereIn('company_id', $subCompanies))
            ->whereNot(fn (Builder $f) => $f->where('kind', TaskBoard::KIND_FRANCHISE)
                ->whereIn('franchise_id', Franchise::query()->whereIn('company_id', $subCompanies)->select('id')));
    }

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
        if ($board->kind === TaskBoard::KIND_USER && $board->owner_user_id === $user->getKey()) {
            return TaskBoardMember::OWNER;
        }

        return TaskBoardMember::query()->where('board_id', $board->getKey())
            ->where('user_id', $user->ownRowsKey())->value('role')
            ?? ($this->implicitUserIds($board)?->where('user_id', $user->ownRowsKey())->exists()
                ? TaskBoardMember::MEMBER : null);
    }

    /**
     * Who is on a company or franchise board without a row (Jose, 2026-10-28):
     * everyone assigned at exactly that company or franchise. Read live from
     * assignments, so it follows them; no cascade to a company's franchises
     * (an Assignment does not cascade, see App\Models\Assignment). Null for
     * boards that have none (personal and custom).
     */
    public function implicitUserIds(TaskBoard $board): ?Builder
    {
        $column = match ($board->kind) {
            TaskBoard::KIND_COMPANY => 'assigned_company_id',
            TaskBoard::KIND_FRANCHISE => 'assigned_franchise_id',
            default => null,
        };
        if ($column === null) {
            return null;
        }

        return Assignment::query()
            ->where($column, $board->kind === TaskBoard::KIND_COMPANY ? $board->company_id : $board->franchise_id)
            ->whereIn('user_id', User::query()->where('is_active', true)->select('id'))
            ->select('user_id');
    }

    /**
     * Boards whose tasks also show on this one, as the same rows (Jose,
     * 2026-10-28): on a company board, its branches' boards, each only when
     * the person may view it there. There is no automatic organization board,
     * so nothing rolls further up.
     *
     * @return Collection<int, TaskBoard>
     */
    public function rolledUpBoards(User $user, TaskBoard $board): Collection
    {
        if ($board->kind !== TaskBoard::KIND_COMPANY) {
            return collect();
        }

        return TaskBoard::query()->where('kind', TaskBoard::KIND_FRANCHISE)
            ->whereIn('franchise_id', Franchise::query()->where('company_id', $board->company_id)->select('id'))
            ->whereIn('id', $this->visibleTo($user)->select('id'))
            ->get();
    }

    /**
     * Implicit members without a row of their own: a manual row always wins.
     *
     * @return Collection<int, User>
     */
    public function implicitMembers(TaskBoard $board): Collection
    {
        $ids = $this->implicitUserIds($board);
        if ($ids === null) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)
            ->whereNotIn('id', $board->members()->select('user_id'))
            ->orderBy('name')->get(['id', 'name']);
    }

    /** Owners and Administrators rename and delete custom boards and own their rules. */
    public function canManage(User $user, TaskBoard $board): bool
    {
        return $this->canView($user, $board)
            && ($user->isAdministrator() || $this->roleOf($user, $board) === TaskBoardMember::OWNER);
    }

    /**
     * Who adds and removes people on a board of any kind: its owner, holders
     * of tasks.manage_members, and Administrator (who holds every key). A
     * place board has no owner rows, so only the last two.
     */
    public function canManageMembers(User $user, TaskBoard $board): bool
    {
        return $this->canView($user, $board)
            && ($user->hasPermission('tasks.manage_members') || $this->roleOf($user, $board) === TaskBoardMember::OWNER);
    }

    /**
     * Who may be ADDED to a board. A custom board keeps its place's reach; an
     * automatic board (personal, company, franchise) takes anyone in the
     * organization, since membership itself is what lets them see and edit it.
     *
     * @return Collection<int, User>
     */
    public function candidates(TaskBoard $board): Collection
    {
        return $board->kind === TaskBoard::KIND_CUSTOM
            ? $this->reach($board)
            : User::query()->orderBy('name')->get();
    }

    /** Ids of boards the person is a member, owner or viewer of. */
    private function membershipIds(User $user)
    {
        return TaskBoardMember::query()->where('user_id', $user->ownRowsKey())->select('board_id');
    }

    /**
     * Why the board is in the person's list: mine (my personal board or one I
     * own), shared (I am on it, not as owner), place (the current view reaches
     * it), everyone (only tasks.view_all shows it).
     */
    public function sectionOf(User $user, TaskBoard $board, ?string $role): string
    {
        if ($role === TaskBoardMember::OWNER) {
            return 'mine';
        }
        if ($role !== null) {
            return 'shared';
        }
        if ($board->kind === TaskBoard::KIND_USER || $board->isPrivate()) {
            return 'everyone';
        }
        // A company board the person reaches by role sits with their team boards, first by
        // position (Jose 11-18: A-1 Chimney at the top), with no membership row to widen anything.
        if ($board->kind === TaskBoard::KIND_COMPANY) {
            return 'shared';
        }

        return 'place';
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
        if ($board->kind === TaskBoard::KIND_USER || $board->isPrivate()) {
            return User::query()->orderBy('name')
                ->whereIn('id', $board->members()->whereIn('role', [TaskBoardMember::OWNER, TaskBoardMember::MEMBER])->select('user_id'))
                ->when($board->kind === TaskBoard::KIND_USER, fn ($q) => $q->orWhere('id', $board->owner_user_id))
                ->get(['id', 'name']);
        }

        return $this->reach($board)->merge(
            User::query()->whereIn('id', $board->members()->whereIn('role', [TaskBoardMember::OWNER, TaskBoardMember::MEMBER])->select('user_id'))->get(['id', 'name'])
        )->unique('id')->sortBy('name')->values();
    }

    /** @return Builder<TaskBoard> */
    public function visibleTo(User $user): Builder
    {
        // My personal board, and any board I have been added to (of any kind,
        // even a place my reach does not cover).
        $mine = fn (Builder $q) => $q->where(fn (Builder $o) => $o->where('kind', TaskBoard::KIND_USER)
            ->where('owner_user_id', $user->ownRowsKey()))
            ->orWhereIn('id', $this->membershipIds($user))
            // Implicit members: assigned at exactly the board's company or franchise.
            ->orWhere(fn (Builder $c) => $c->where('kind', TaskBoard::KIND_COMPANY)
                ->whereIn('company_id', Assignment::query()->where('user_id', $user->ownRowsKey())->whereNotNull('assigned_company_id')->select('assigned_company_id')))
            ->orWhere(fn (Builder $f) => $f->where('kind', TaskBoard::KIND_FRANCHISE)
                ->whereIn('franchise_id', Assignment::query()->where('user_id', $user->ownRowsKey())->whereNotNull('assigned_franchise_id')->select('assigned_franchise_id')));

        if (! $user->hasPermission('tasks.view')) {
            return $this->withoutSubCompanyBoards(TaskBoard::query()->where($mine));
        }

        [$companyIds, $franchiseIds] = $this->placesInView($user);
        $all = $user->hasPermission('tasks.view_all');

        return $this->withoutSubCompanyBoards(TaskBoard::query())->where(fn (Builder $q) => $q
            ->where($mine)
            // tasks.view_all: everybody's personal board.
            ->when($all, fn (Builder $e) => $e->orWhere('kind', TaskBoard::KIND_USER))
            ->orWhere(fn (Builder $c) => $c->where('kind', TaskBoard::KIND_COMPANY)->whereIn('company_id', $companyIds))
            ->orWhere(fn (Builder $f) => $f->where('kind', TaskBoard::KIND_FRANCHISE)->whereIn('franchise_id', $franchiseIds))
            // A custom board: its place is in view (or it is organization-level),
            // and it is main, or private and I am on it (or I hold tasks.view_all).
            ->orWhere(fn (Builder $c) => $c->where('kind', TaskBoard::KIND_CUSTOM)
                ->where(fn (Builder $p) => $p->whereIn('company_id', $companyIds)
                    ->orWhereIn('franchise_id', $franchiseIds)
                    ->orWhere(fn (Builder $o) => $o->whereNull('company_id')->whereNull('franchise_id')))
                ->when(! $all, fn (Builder $v) => $v->where(fn (Builder $m) => $m
                    ->where('visibility', TaskBoard::MAIN)
                    ->orWhereIn('id', TaskBoardMember::query()->where('user_id', $user->ownRowsKey())->select('board_id'))))));
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
            if ($board->owner_user_id === $user->getKey()) {
                return true;
            }
            $role = $this->roleOf($user, $board);

            // A member writes a personal board; so does an Administrator who can see it.
            return $this->canView($user, $board)
                && (($role !== null && $role !== TaskBoardMember::VIEWER) || $user->isAdministrator());
        }

        if (! $user->hasPermission('tasks.edit') || ! $this->canView($user, $board)) {
            return false;
        }

        // A member of a place board writes it even without reach to the place.
        if ($board->kind !== TaskBoard::KIND_CUSTOM
            && in_array($this->roleOf($user, $board), [TaskBoardMember::OWNER, TaskBoardMember::MEMBER], true)) {
            return true;
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
