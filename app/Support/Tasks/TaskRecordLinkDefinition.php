<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface TaskRecordLinkDefinition
{
    public function key(): string;

    public function label(): string;

    /** @return array<string,string> */
    public function snapshotClassification(): array;

    /** @return Collection<int,array<string,mixed>> */
    public function search(User $user, TaskBoard $board, ViewScope $scope, string $query): Collection;

    public function resolve(User $user, TaskBoard $board, ViewScope $scope, string $id): ?Model;

    /** @return array<string,mixed> */
    public function present(Model $record): array;
}
