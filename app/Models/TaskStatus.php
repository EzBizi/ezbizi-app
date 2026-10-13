<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One status on a board. The four built-ins carry their old wire `key`;
 * custom ones have none and map tasks.status to done / not_started.
 */
class TaskStatus extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'board_id', 'key', 'name', 'colour', 'position', 'is_done'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_done' => 'boolean'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A status is part of its board and goes with it.');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    /** What tasks.status holds for a task on this status (Reports, Automations). */
    public function statusKey(): string
    {
        // A built-in keeps its key while its done-ness still matches the key's.
        if ($this->key !== null && ($this->key === Task::STATUS_DONE) === $this->is_done) {
            return $this->key;
        }

        return $this->is_done ? Task::STATUS_DONE : Task::STATUS_NOT_STARTED;
    }
}
