<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskFolder;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskFolders
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $actor, ViewScope $scope, string $name): TaskFolder
    {
        $this->access->createDecision($actor, $scope)->authorize();
        $place = TaskPlace::fromView($scope);

        return DB::transaction(function () use ($actor, $place, $name): TaskFolder {
            $this->assertNameAvailable($place, $name);
            $folder = new TaskFolder;
            $folder->forceFill([
                ...$place,
                'name' => $name,
                'position' => $this->nextPosition($place),
            ])->save();
            $auditScope = TaskPlace::auditScope($folder);
            $this->audit->log('task_folder.created', target: $folder,
                changes: ['before' => [], 'after' => ['name' => $folder->name, 'position' => $folder->position]],
                metadata: ['folder_id' => $folder->id], actor: $actor,
                scopeType: $auditScope['type'], scopeId: $auditScope['id']);

            return $folder->fresh();
        });
    }

    public function update(User $actor, TaskFolder $folder, ViewScope $scope, string $name): TaskFolder
    {
        return DB::transaction(function () use ($actor, $folder, $scope, $name): TaskFolder {
            $locked = TaskFolder::query()->lockForUpdate()->findOrFail($folder->id);
            $place = $this->authorizeAtCurrentPlace($actor, $locked, $scope);
            $this->assertNameAvailable($place, $name, $locked->id);
            $beforeName = $locked->name;
            $locked->forceFill(['name' => $name])->save();
            $auditScope = TaskPlace::auditScope($locked);
            $this->audit->log('task_folder.updated', target: $locked,
                changes: ['before' => ['name' => $beforeName], 'after' => ['name' => $locked->name]],
                metadata: ['folder_id' => $locked->id], actor: $actor,
                scopeType: $auditScope['type'], scopeId: $auditScope['id']);

            return $locked->fresh();
        });
    }

    public function setArchived(User $actor, TaskFolder $folder, ViewScope $scope, bool $archived): TaskFolder
    {
        return DB::transaction(function () use ($actor, $folder, $scope, $archived): TaskFolder {
            $locked = TaskFolder::query()->lockForUpdate()->findOrFail($folder->id);
            $place = $this->authorizeAtCurrentPlace($actor, $locked, $scope);

            if ($archived && TaskBoard::query()->active()->where('folder_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'folder' => 'Move or archive the active boards in this folder first.',
                ]);
            }

            if (! $archived && TaskFolder::query()->active()->whereKeyNot($locked->id)
                ->where('company_id', $place['company_id'])
                ->where('franchise_id', $place['franchise_id'])
                ->where('position', $locked->position)->exists()) {
                $locked->position = $this->nextPosition($place);
            }

            $beforeArchivedAt = $locked->archived_at?->toIso8601String();
            $locked->archived_at = $archived ? now() : null;
            $locked->save();
            $auditScope = TaskPlace::auditScope($locked);
            $this->audit->log($archived ? 'task_folder.archived' : 'task_folder.restored', target: $locked,
                changes: ['before' => ['archived_at' => $beforeArchivedAt], 'after' => ['archived_at' => $locked->archived_at?->toIso8601String()]], metadata: [
                'folder_id' => $locked->id,
            ], actor: $actor, scopeType: $auditScope['type'], scopeId: $auditScope['id']);

            return $locked->fresh();
        });
    }

    /** @return array{organization_id:string,company_id:?string,franchise_id:?string} */
    private function authorizeAtCurrentPlace(User $actor, TaskFolder $folder, ViewScope $scope): array
    {
        $this->access->createDecision($actor, $scope)->authorize();
        $place = TaskPlace::fromView($scope);
        abort_unless(
            $folder->organization_id === $place['organization_id']
                && $folder->company_id === $place['company_id']
                && $folder->franchise_id === $place['franchise_id'],
            404,
        );

        return $place;
    }

    private function assertNameAvailable(array $place, string $name, ?string $exceptId = null): void
    {
        $query = TaskFolder::query()
            ->where('company_id', $place['company_id'])
            ->where('franchise_id', $place['franchise_id'])
            ->where('name', $name);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'That folder name is already used at this place.']);
        }
    }

    private function nextPosition(array $place): int
    {
        return (int) TaskFolder::query()->active()
            ->where('company_id', $place['company_id'])
            ->where('franchise_id', $place['franchise_id'])
            ->lockForUpdate()->max('position') + 1;
    }
}
