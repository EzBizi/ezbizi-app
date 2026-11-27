<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved view of a Task Board: its filter, sort, person filter and hidden
 * columns under a name (Jose, 2026-11-09). user_id null is shared with the
 * board; set, it is that person's own. config: {match: all|any,
 * filters: [{field, op, value}], sort: [{field, dir}], people: [ids], hidden: [keys]}.
 */
class TaskBoardView extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'board_id', 'user_id', 'name', 'config', 'position'];

    protected function casts(): array
    {
        return ['config' => 'array', 'position' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A saved view is part of its board and goes with it, or with the person who saved it.');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }
}
