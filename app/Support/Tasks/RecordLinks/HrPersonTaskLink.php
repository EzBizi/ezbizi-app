<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\User;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class HrPersonTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'hr_person';
    }

    public function label(): string
    {
        return 'Person';
    }

    protected function modelClass(): string
    {
        return User::class;
    }

    protected function searchColumns(): array
    {
        return ['name', 'email'];
    }

    protected function relations(): array
    {
        return ['assignments'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->name, 'subtitle' => null, 'route' => "/hr/people/{$record->id}"];
    }
}
