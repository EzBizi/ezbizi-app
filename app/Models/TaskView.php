<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskView extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'owner_user_id', 'visibility', 'name', 'is_locked', 'configuration'];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean', 'is_permanent_main' => 'boolean', 'configuration' => 'array', 'version' => 'integer', 'position' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Saved view definitions remain until deleted by an authorized owner; the permanent Main view remains for the life of the board.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Personal or shared saved filters, searches, layout, and ownership for a task board.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('owner_user_id', PersonalDataEntry::ACTIVITY, 'The person who owns and privately edits a personal saved view.', Erasure::drop('A personal view has no purpose after its owner is erased; shared and Main views have no personal owner.')),
            new PersonalDataEntry('name, configuration', PersonalDataEntry::CONTENT, 'The saved view name, filters, search text, hidden columns, layout, and grouping chosen by the tenant.', Erasure::keep('Shared configuration remains business structure; personal-view deletion handles owner-specific rows.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('owner_user_id', $subject->getKey()) : null;
    }

    protected static function booted(): void
    {
        static::deleting(function (self $view): void {
            $view->preferences()->delete();
        });
    }

    public function scopePersonal(Builder $query): Builder
    {
        return $query->where('visibility', 'personal');
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('visibility', 'shared');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(TaskViewPreference::class, 'view_id');
    }
}
