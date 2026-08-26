<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class TaskOverdueOccurrence extends Model implements StatesRetention
{
    use BelongsToTenant, HasUuids, WrittenOnce;

    public const UPDATED_AT = null;

    protected $fillable = ['board_id', 'task_id', 'due_revision', 'due_on', 'created_at'];

    protected function casts(): array
    {
        return ['due_revision' => 'integer', 'due_on' => 'date', 'created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The due-revision occurrence key remains as durable idempotency evidence for the task history.');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Task overdue occurrences are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Task overdue occurrences are immutable.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
