<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Task Board change waiting to be written to its Monday item (see
 * App\Support\Tasks\Monday\MondayOutbox). Holds field names, never values:
 * the worker reads the task when it sends.
 */
class MondayOutboxEntry extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const SUPERSEDED = 'superseded';

    public const FAILED = 'failed';

    protected $table = 'monday_outbox';

    protected $fillable = ['organization_id', 'task_id', 'task_update_id', 'kinds', 'backfill', 'state', 'attempts', 'changed_at', 'available_at', 'sent_at', 'error'];

    protected function casts(): array
    {
        return ['kinds' => 'array', 'backfill' => 'boolean', 'attempts' => 'integer', 'changed_at' => 'datetime', 'available_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'A note that a task changed and Monday was told; it names fields, not what was in them, and matters only while Monday is still in use.');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function taskUpdate(): BelongsTo
    {
        return $this->belongsTo(TaskUpdate::class, 'task_update_id')->withTrashed();
    }
}
