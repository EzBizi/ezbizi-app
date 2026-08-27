<?php

namespace App\Support\Tasks;

use InvalidArgumentException;

final class TaskRecordLinkRegistry
{
    /** @var array<string,TaskRecordLinkDefinition> */
    private array $definitions = [];

    public function register(TaskRecordLinkDefinition $definition): void
    {
        $this->definitions[$definition->key()] = $definition;
    }

    public function get(string $key): TaskRecordLinkDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException('That linked-record type is unavailable.');
    }

    /** @return list<array{key:string,label:string,snapshot_classification:array<string,string>}> */
    public function catalogue(): array
    {
        return array_values(array_map(fn (TaskRecordLinkDefinition $definition) => [
            'key' => $definition->key(), 'label' => $definition->label(),
            'snapshot_classification' => $definition->snapshotClassification(),
        ], $this->definitions));
    }
}
