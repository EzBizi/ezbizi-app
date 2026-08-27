<?php

namespace App\Support\Tasks\RecordLinks;

use App\Models\InventoryItem;
use App\Support\Tasks\AbstractTaskRecordLinkDefinition;
use Illuminate\Database\Eloquent\Model;

final class InventoryItemTaskLink extends AbstractTaskRecordLinkDefinition
{
    public function key(): string
    {
        return 'inventory_item';
    }

    public function label(): string
    {
        return 'Inventory item';
    }

    protected function modelClass(): string
    {
        return InventoryItem::class;
    }

    protected function searchColumns(): array
    {
        return ['number', 'sku', 'name'];
    }

    protected function display(Model $record): array
    {
        return ['label' => $record->displayName(), 'subtitle' => $record->number, 'route' => "/inventory/items/{$record->id}"];
    }
}
