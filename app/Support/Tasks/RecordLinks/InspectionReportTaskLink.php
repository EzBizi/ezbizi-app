<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\InspectionReport;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class InspectionReportTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'inspection_report';
    }

    public function label(): string
    {
        return 'Inspection report';
    }

    protected function modelClass(): string
    {
        return InspectionReport::class;
    }

    protected function searchColumns(): array
    {
        return ['definition_name'];
    }

    protected function relations(): array
    {
        return ['job', 'customer'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->definition_name, 'subtitle' => $record->submitted_at?->format('Y-m-d'), 'route' => "/inspections/reports/{$record->id}"];
    }
}
