<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\Service;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class ServiceTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'service';
    }

    public function label(): string
    {
        return 'Service';
    }

    protected function modelClass(): string
    {
        return Service::class;
    }

    protected function searchColumns(): array
    {
        return ['number', 'name'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->name, 'subtitle' => $record->number, 'route' => "/services/{$record->id}"];
    }
}
