<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

abstract class AbstractTaskRecordLinkDefinition implements TaskRecordLinkDefinition
{
    public function __construct(private readonly TaskRecordLinkScope $scopeFilter) {}

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return list<string> */
    abstract protected function searchColumns(): array;

    /** @return list<string> */
    protected function relations(): array
    {
        return [];
    }

    /** @return array{label:string,subtitle:?string,route:string} */
    abstract protected function display(Model $record): array;

    public function snapshotClassification(): array
    {
        return ['label' => 'content', 'subtitle' => 'content'];
    }

    public function search(User $user, TaskBoard $board, ViewScope $scope, string $query): Collection
    {
        $class = $this->modelClass();
        $records = $class::query()->with($this->relations())
            ->where(function (Builder $builder) use ($query): void {
                foreach ($this->searchColumns() as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}($column, 'like', '%'.$query.'%');
                }
            })->limit(100)->get();

        return $records->filter(fn (Model $record) => $this->available($user, $board, $scope, $record))
            ->take(20)->map(fn (Model $record) => $this->present($record))->values();
    }

    public function resolve(User $user, TaskBoard $board, ViewScope $scope, string $id): ?Model
    {
        $class = $this->modelClass();
        $record = $class::query()->with($this->relations())->whereKey($id)->first();

        return $record !== null && $this->available($user, $board, $scope, $record) ? $record : null;
    }

    public function present(Model $record): array
    {
        return ['id' => $record->getKey(), 'type' => $this->key(), ...$this->display($record)];
    }

    private function available(User $user, TaskBoard $board, ViewScope $scope, Model $record): bool
    {
        return Gate::forUser($user)->allows('view', $record)
            && $this->scopeFilter->contains($record, $board, $scope);
    }
}
