<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A user-made folder in the Task Board list (Monday's workspace folders). */
class TaskBoardFolder extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'parent_id', 'name', 'colour', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A folder is part of the organization\'s board list and goes with it.');
    }
}
