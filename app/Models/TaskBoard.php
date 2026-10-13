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
 * One Task Board: a person's own, a franchise's, or a company's (Jose,
 * 2026-10-13). Exactly one of owner_user_id / franchise_id / company_id is set,
 * matching `kind`. Created automatically — see App\Support\Tasks\TaskBoards.
 */
class TaskBoard extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KIND_USER = 'user';

    public const KIND_FRANCHISE = 'franchise';

    public const KIND_COMPANY = 'company';

    protected $fillable = ['organization_id', 'kind', 'company_id', 'franchise_id', 'owner_user_id', 'name'];

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
