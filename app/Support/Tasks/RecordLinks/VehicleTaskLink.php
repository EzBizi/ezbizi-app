<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\Vehicle;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class VehicleTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'vehicle';
    }

    public function label(): string
    {
        return 'Vehicle';
    }

    protected function modelClass(): string
    {
        return Vehicle::class;
    }

    protected function searchColumns(): array
    {
        return ['number', 'label', 'plate', 'vin'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->label, 'subtitle' => $record->number, 'route' => "/fleet/vehicles/{$record->id}"];
    }
}
