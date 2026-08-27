<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\Job;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class JobTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'job';
    }

    public function label(): string
    {
        return 'Job';
    }

    protected function modelClass(): string
    {
        return Job::class;
    }

    protected function searchColumns(): array
    {
        return ['number', 'title'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->title, 'subtitle' => $record->number, 'route' => "/jobs/{$record->id}"];
    }
}
