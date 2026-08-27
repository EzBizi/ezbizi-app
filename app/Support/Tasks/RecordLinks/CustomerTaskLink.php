<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\Customer;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class CustomerTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'customer';
    }

    public function label(): string
    {
        return 'Customer';
    }

    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function searchColumns(): array
    {
        return ['number', 'name'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->name, 'subtitle' => $record->number, 'route' => "/customers/{$record->id}"];
    }
}
