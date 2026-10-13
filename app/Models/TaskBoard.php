<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One Task Board: a person's own, a franchise's, or a company's (Jose,
 * 2026-10-13). Exactly one of owner_user_id / franchise_id / company_id is set,
 * matching `kind`. Created automatically — see App\Support\Tasks\TaskBoards.
 *
 * A `custom` board (inbox 0120) is made by a person: it sits on a company, a
 * franchise or the organization (both ids null), is `main` or `private`, and
 * has members. Automatic boards are always main and cannot be deleted.
 */
class TaskBoard extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    public const KIND_USER = 'user';

    public const KIND_FRANCHISE = 'franchise';

    public const KIND_COMPANY = 'company';

    public const KIND_CUSTOM = 'custom';

    public const MAIN = 'main';

    public const PRIVATE = 'private';

    protected $fillable = ['organization_id', 'kind', 'company_id', 'franchise_id', 'owner_user_id', 'created_by_user_id', 'name', 'visibility', 'auto_key'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A board exists because its company, franchise or person does, and goes with them.'
        );
    }

    public function groups(): HasMany
    {
        return $this->hasMany(TaskGroup::class, 'board_id')->orderBy('position');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TaskStatus::class, 'board_id')->orderBy('position');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(TaskColumn::class, 'board_id')->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'board_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TaskBoardMember::class, 'board_id');
    }

    public function isAutomatic(): bool
    {
        return $this->kind !== self::KIND_CUSTOM;
    }

    public function isPrivate(): bool
    {
        return $this->kind === self::KIND_CUSTOM && $this->visibility === self::PRIVATE;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
