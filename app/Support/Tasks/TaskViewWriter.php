<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskGroup;
use App\Models\TaskView;
use App\Models\TaskViewPreference;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskViewWriter
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskViewCodec $codec,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{view:TaskView,structure_version:int} */
    public function create(User $actor, TaskBoard $board, ViewScope $scope, array $input): array
    {
        return DB::transaction(function () use ($actor, $board, $scope, $input): array {
            $locked = $this->lockedBoard($actor, $board, $scope);
            $this->writable($actor);
            $shared = $input['visibility'] === 'shared';
            if (! $shared && (bool) ($input['is_locked'] ?? false)) {
                throw ValidationException::withMessages(['is_locked' => 'A personal view cannot be locked.']);
            }
            if ($shared) {
                $this->access->manageDecision($actor, $locked, $scope)->authorize();
                $this->assertStructureVersion($locked, $input['expected_structure_version'] ?? null);
            }
            $configuration = $this->codec->decode($locked, $input['configuration']);
            $ownerId = $shared ? null : $actor->id;
            $this->uniqueName($locked, $input['visibility'], $ownerId, $input['name']);
            $view = new TaskView;
            $view->forceFill([
                'board_id' => $locked->id,
                'owner_user_id' => $ownerId,
                'visibility' => $input['visibility'],
                'name' => $input['name'],
                'is_locked' => $shared && (bool) ($input['is_locked'] ?? false),
                'is_permanent_main' => false,
                'configuration' => $configuration,
                'position' => $this->nextPosition($locked, $input['visibility'], $ownerId),
            ])->save();
            if ($shared) {
                $this->bumpStructure($locked);
            }
            $this->record('task_view.created', $actor, $locked, $view, [], $this->auditFields($view));

            return ['view' => $view->fresh(), 'structure_version' => (int) $locked->structure_version];
        });
    }

    /** @return array{view:TaskView,structure_version:int} */
    public function update(User $actor, TaskBoard $board, ViewScope $scope, string $viewId, array $input): array
    {
        return DB::transaction(function () use ($actor, $board, $scope, $viewId, $input): array {
            $locked = $this->lockedBoard($actor, $board, $scope);
            $view = TaskView::query()->where('board_id', $locked->id)->lockForUpdate()->findOrFail($viewId);
            $this->writable($actor);
            $this->assertOwnerCanSee($actor, $view);
            $this->assertViewVersion($view, (int) $input['expected_view_version']);

            $visibility = $input['visibility'] ?? $view->visibility;
            $isLocked = $visibility === 'shared' ? (bool) ($input['is_locked'] ?? $view->is_locked) : false;
            if ($view->is_permanent_main && $visibility !== 'shared') {
                throw ValidationException::withMessages(['visibility' => 'The permanent Main view must remain shared.']);
            }
            if ($visibility === 'personal' && (bool) ($input['is_locked'] ?? false)) {
                throw ValidationException::withMessages(['is_locked' => 'A personal view cannot be locked.']);
            }

            $controlChange = $visibility !== $view->visibility || $isLocked !== (bool) $view->is_locked;
            if ($controlChange || ($view->visibility === 'shared' && $view->is_locked)) {
                $this->access->manageDecision($actor, $locked, $scope)->authorize();
            } elseif ($view->visibility === 'shared' && ! $actor->hasPermission('tasks.edit')) {
                Refusal::permission('edit shared task views')->authorize();
            }
            if ($controlChange) {
                $this->assertStructureVersion($locked, $input['expected_structure_version'] ?? null);
            }

            $ownerId = $visibility === 'personal' ? $actor->id : null;
            $name = $input['name'] ?? $view->name;
            $this->uniqueName($locked, $visibility, $ownerId, $name, $view->id);
            $before = $this->auditFields($view);
            if (array_key_exists('configuration', $input)) {
                $view->configuration = $this->codec->decode($locked, $input['configuration']);
            }
            if ($visibility !== $view->visibility) {
                $view->position = $this->nextPosition($locked, $visibility, $ownerId);
            }
            $view->forceFill([
                'name' => $name,
                'visibility' => $visibility,
                'owner_user_id' => $ownerId,
                'is_locked' => $isLocked,
                'version' => (int) $view->version + 1,
            ])->save();
            if ($controlChange) {
                $this->bumpStructure($locked);
            }
            $this->record('task_view.updated', $actor, $locked, $view, $before, $this->auditFields($view));

            return ['view' => $view->fresh(), 'structure_version' => (int) $locked->structure_version];
        });
    }

    public function delete(User $actor, TaskBoard $board, ViewScope $scope, string $viewId, array $input): int
    {
        return DB::transaction(function () use ($actor, $board, $scope, $viewId, $input): int {
            $locked = $this->lockedBoard($actor, $board, $scope);
            $view = TaskView::query()->where('board_id', $locked->id)->lockForUpdate()->findOrFail($viewId);
            if ($view->is_permanent_main) {
                throw ValidationException::withMessages(['view' => 'The permanent Main view cannot be deleted.']);
            }
            $this->writable($actor);
            $before = $this->auditFields($view);
            if ($view->visibility === 'shared') {
                $this->access->manageDecision($actor, $locked, $scope)->authorize();
                $this->assertStructureVersion($locked, $input['expected_structure_version'] ?? null);
            } else {
                $this->assertOwnerCanSee($actor, $view);
                if (! isset($input['expected_view_version'])) {
                    throw ValidationException::withMessages(['expected_view_version' => 'The current view version is required.']);
                }
                $this->assertViewVersion($view, (int) $input['expected_view_version']);
            }
            $view->delete();
            if ($view->visibility === 'shared') {
                $this->bumpStructure($locked);
            }
            $this->record('task_view.deleted', $actor, $locked, $view, $before, []);

            return (int) $locked->structure_version;
        });
    }

    public function duplicate(User $actor, TaskBoard $board, ViewScope $scope, string $viewId, string $name, int $expected): TaskView
    {
        return DB::transaction(function () use ($actor, $board, $scope, $viewId, $name, $expected): TaskView {
            $locked = $this->lockedBoard($actor, $board, $scope);
            $this->writable($actor);
            $source = TaskView::query()->where('board_id', $locked->id)->where('visibility', 'shared')
                ->lockForUpdate()->findOrFail($viewId);
            $this->assertViewVersion($source, $expected);
            $this->uniqueName($locked, 'personal', $actor->id, $name);
            $copy = new TaskView;
            $copy->forceFill([
                'board_id' => $locked->id,
                'owner_user_id' => $actor->id,
                'visibility' => 'personal',
                'name' => $name,
                'is_locked' => false,
                'is_permanent_main' => false,
                'configuration' => $source->configuration,
                'position' => $this->nextPosition($locked, 'personal', $actor->id),
            ])->save();
            $this->record('task_view.duplicated', $actor, $locked, $copy, [], $this->auditFields($copy));

            return $copy->fresh();
        });
    }

    /** @return array{views:array<int,TaskView>,structure_version:int} */
    public function reorder(User $actor, TaskBoard $board, ViewScope $scope, array $input): array
    {
        return DB::transaction(function () use ($actor, $board, $scope, $input): array {
            $locked = $this->lockedBoard($actor, $board, $scope);
            $this->writable($actor);
            $shared = $input['visibility'] === 'shared';
            $ownerId = $shared ? null : $actor->id;
            if ($shared) {
                $this->access->manageDecision($actor, $locked, $scope)->authorize();
                $this->assertStructureVersion($locked, $input['expected_structure_version'] ?? null);
            }
            $views = TaskView::query()->where('board_id', $locked->id)->where('visibility', $input['visibility'])
                ->when($ownerId !== null, fn ($query) => $query->where('owner_user_id', $ownerId))
                ->orderBy('position')->lockForUpdate()->get()->keyBy('id');
            if ($views->count() !== count($input['view_ids']) || array_diff($views->modelKeys(), $input['view_ids']) !== []) {
                throw ValidationException::withMessages(['view_ids' => 'Submit every view in this ordering scope exactly once.']);
            }
            if (! $shared) {
                foreach ($views as $view) {
                    $this->assertViewVersion($view, (int) ($input['expected_view_versions'][$view->id] ?? 0));
                }
            }
            $before = $views->sortBy('position')->keys()->values()->all();
            foreach ($views->values() as $offset => $view) {
                $view->forceFill(['position' => -1000 - $offset])->save();
            }
            foreach ($input['view_ids'] as $position => $id) {
                $view = $views[$id];
                $view->forceFill([
                    'position' => $position,
                    'version' => $shared ? $view->version : (int) $view->version + 1,
                ])->save();
            }
            if ($shared) {
                $this->bumpStructure($locked);
            }
            $this->record('task_view.reordered', $actor, $locked, $locked, ['view_ids' => $before], ['view_ids' => $input['view_ids']]);

            return [
                'views' => collect($input['view_ids'])->map(fn ($id) => $views[$id]->fresh())->all(),
                'structure_version' => (int) $locked->structure_version,
            ];
        });
    }

    public function preference(User $actor, TaskBoard $board, ViewScope $scope, string $viewId, array $input): TaskViewPreference
    {
        return DB::transaction(function () use ($actor, $board, $scope, $viewId, $input): TaskViewPreference {
            $locked = $this->lockedBoard($actor, $board, $scope);
            $this->writable($actor);
            $view = TaskView::query()->where('board_id', $locked->id)
                ->where(fn ($visible) => $visible->where('visibility', 'shared')->orWhere('owner_user_id', $actor->id))
                ->lockForUpdate()->findOrFail($viewId);
            $groupCount = TaskGroup::query()->where('board_id', $locked->id)->active()
                ->whereIn('id', $input['collapsed_group_ids'])->count();
            if ($groupCount !== count($input['collapsed_group_ids'])) {
                throw ValidationException::withMessages(['collapsed_group_ids' => 'Collapsed groups must be active groups on this board.']);
            }
            $preference = TaskViewPreference::query()->where('view_id', $view->id)->where('user_id', $actor->id)
                ->lockForUpdate()->first();
            $before = $preference === null ? [] : $this->preferenceFields($preference);
            if ($input['is_last_active']) {
                TaskViewPreference::query()->where('board_id', $locked->id)->where('user_id', $actor->id)
                    ->update(['is_last_active' => false]);
            }
            $preference ??= new TaskViewPreference;
            $preference->forceFill([
                'board_id' => $locked->id, 'view_id' => $view->id, 'user_id' => $actor->id, ...$input,
            ])->save();
            $this->record('task_view.preference_updated', $actor, $locked, $preference, $before, $this->preferenceFields($preference));

            return $preference->fresh();
        });
    }

    private function lockedBoard(User $actor, TaskBoard $board, ViewScope $scope): TaskBoard
    {
        $locked = TaskBoard::query()->active()->lockForUpdate()->findOrFail($board->id);
        if ($locked->visibility === BoardVisibility::Private) {
            TaskBoardMember::query()->where('board_id', $locked->id)->where('user_id', $actor->id)
                ->lockForUpdate()->first();
        }
        $this->access->viewDecision($actor, $locked, $scope)->authorize();

        return $locked;
    }

    private function assertOwnerCanSee(User $actor, TaskView $view): void
    {
        if ($view->visibility === 'personal' && $view->owner_user_id !== $actor->id) {
            abort(404);
        }
    }

    private function assertViewVersion(TaskView $view, int $expected): void
    {
        if ((int) $view->version !== $expected) {
            throw new TaskViewConflict($view->fresh());
        }
    }

    private function assertStructureVersion(TaskBoard $board, mixed $expected): void
    {
        if (! is_int($expected)) {
            throw ValidationException::withMessages(['expected_structure_version' => 'The current board structure version is required.']);
        }
        if ((int) $board->structure_version !== $expected) {
            throw new TaskStructureConflict((int) $board->structure_version);
        }
    }

    private function bumpStructure(TaskBoard $board): void
    {
        $board->forceFill(['structure_version' => (int) $board->structure_version + 1])->save();
    }

    private function writable(User $actor): void
    {
        if ($actor->organization?->isReadOnly()) {
            throw ValidationException::withMessages(['organization' => 'This organization is read-only because its subscription has ended.']);
        }
    }

    private function uniqueName(TaskBoard $board, string $visibility, ?string $ownerId, string $name, ?string $except = null): void
    {
        $query = TaskView::query()->where('board_id', $board->id)->where('visibility', $visibility)
            ->when($ownerId === null, fn ($scope) => $scope->whereNull('owner_user_id'), fn ($scope) => $scope->where('owner_user_id', $ownerId))
            ->where('name', $name)->when($except !== null, fn ($scope) => $scope->whereKeyNot($except));
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A view with this name already exists in that scope.']);
        }
    }

    private function nextPosition(TaskBoard $board, string $visibility, ?string $ownerId): int
    {
        return (int) TaskView::query()->where('board_id', $board->id)->where('visibility', $visibility)
            ->when($ownerId === null, fn ($scope) => $scope->whereNull('owner_user_id'), fn ($scope) => $scope->where('owner_user_id', $ownerId))
            ->max('position') + 1;
    }

    private function auditFields(TaskView $view): array
    {
        return [
            'id' => $view->id, 'name' => $view->name, 'visibility' => $view->visibility,
            'owner_user_id' => $view->owner_user_id, 'is_locked' => (bool) $view->is_locked,
            'configuration' => $view->configuration, 'version' => (int) $view->version,
            'position' => (int) $view->position,
        ];
    }

    private function preferenceFields(TaskViewPreference $preference): array
    {
        return [
            'view_id' => $preference->view_id,
            'collapsed_group_ids' => $preference->collapsed_group_ids,
            'density' => $preference->density,
            'is_last_active' => (bool) $preference->is_last_active,
            'scroll_left' => (int) $preference->scroll_left,
            'scroll_top' => (int) $preference->scroll_top,
        ];
    }

    private function record(string $action, User $actor, TaskBoard $board, $target, array $before, array $after): void
    {
        $place = TaskPlace::auditScope($board);
        $this->audit->log($action, target: $target, changes: ['before' => $before, 'after' => $after], metadata: [
            'board_id' => $board->id,
        ], actor: $actor, scopeType: $place['type'], scopeId: $place['id']);
    }
}
