<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person on a Task Board, monday-style: owner, member or viewer. No free
 * text: a board id, a user id and a role.
 */
class TaskBoardMember extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const OWNER = 'owner';

    public const MEMBER = 'member';

    public const VIEWER = 'viewer';

    public const ROLES = [self::OWNER, self::MEMBER, self::VIEWER];

    protected $fillable = ['organization_id', 'board_id', 'user_id', 'role'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A membership is part of its board and goes with it, or with the person.');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
