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

    /**
     * Jose, 2026-11-09, Monday-style: long_text, currency, phone, email, url,
     * datetime, tags (several options, a JSON list of ids) and link (a
     * customer or job, "customer:<id>" / "job:<id>") joined the first nine.
     */
    public const TYPES = ['text', 'number', 'status', 'date', 'person', 'dropdown', 'checkbox', 'timeline', 'formula',
        'long_text', 'currency', 'phone', 'email', 'url', 'datetime', 'tags', 'link', 'people',
        'location', 'rating', 'files', 'last_updated', 'creation_log', 'connect', 'dependency', 'mirror'];

    /** How a number or formula shows (design 178): plain, currency or a percentage. */
    public const FORMATS = ['plain', 'currency', 'percent'];

    /** What a group's footer shows under a number or formula column. */
    public const FOOTERS = ['sum', 'average', 'none'];

    public const WITH_OPTIONS = ['status', 'dropdown', 'tags'];

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
