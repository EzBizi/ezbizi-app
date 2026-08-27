<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\InspectionRun;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class InspectionRunTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'inspection_run';
    }

    public function label(): string
    {
        return 'Inspection draft';
    }

    protected function modelClass(): string
    {
        return InspectionRun::class;
    }

    protected function searchColumns(): array
    {
        return ['scope_key'];
    }

    protected function relations(): array
    {
        return ['job', 'customer', 'definition'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->definition?->name ?? 'Inspection draft', 'subtitle' => null, 'route' => "/inspections/runs/{$record->id}"];
    }
}
