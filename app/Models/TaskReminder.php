<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Remind me about this task at …" (Shoval 11-13, before Monday turns off). Rings the person's bell once. */
class TaskReminder extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'task_id', 'user_id', 'remind_at', 'note', 'sent_at'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at', 'A reminder someone set on a task; kept a year so a person can see what they were reminded of.');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
