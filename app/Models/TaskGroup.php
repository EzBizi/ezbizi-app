<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A column of tasks on a board: This week, Next week, Done. */
class TaskGroup extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'board_id', 'name', 'colour', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A group is part of its board and goes with it.');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }
}
