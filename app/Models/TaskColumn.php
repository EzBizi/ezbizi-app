<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A custom column on a board. settings: {unit?: string, options?: [{id, label, colour}]};
 * options exist on status/dropdown columns only.
 */
class TaskColumn extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const TYPES = ['text', 'number', 'status', 'date', 'person', 'dropdown', 'checkbox'];

    public const WITH_OPTIONS = ['status', 'dropdown'];

    protected $fillable = ['organization_id', 'board_id', 'name', 'type', 'settings', 'hidden', 'position'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'hidden' => 'boolean', 'position' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A column is part of its board and goes with it.');
    }

    /** @return array<int, array{id: string, label: string, colour: ?string}> */
    public function options(): array
    {
        return $this->settings['options'] ?? [];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(TaskColumnValue::class, 'column_id');
    }
}
