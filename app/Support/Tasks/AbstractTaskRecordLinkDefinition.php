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
        $needle = $this->like($query);
        $records = $class::query()->with($this->relations())
            ->where(function (Builder $builder) use ($needle): void {
                foreach ($this->searchColumns() as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}($column, 'like', '%'.$needle.'%');
                }
            })->lazyById(100);

        $visible = collect();
        foreach ($records as $record) {
            if ($this->available($user, $board, $scope, $record)) {
                $visible->push($this->present($record));
                if ($visible->count() === 20) {
                    break;
                }
            }
        }

        return $visible;
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

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
