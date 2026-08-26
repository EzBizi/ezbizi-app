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

class TaskViewPreference extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'view_id', 'user_id', 'collapsed_group_ids', 'density', 'is_last_active', 'scroll_left', 'scroll_top'];

    protected function casts(): array
    {
        return ['collapsed_group_ids' => 'array', 'is_last_active' => 'boolean', 'scroll_left' => 'integer', 'scroll_top' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Personal view state remains until the person or organization deliberately removes it.');
    }

    public static function personalDataPurpose(): string
    {
        return 'One user private collapsed groups, density, active view, and scroll position.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('user_id, collapsed_group_ids, density, is_last_active, scroll_left, scroll_top', PersonalDataEntry::ACTIVITY, 'The person and their private task-table presentation state.', Erasure::drop('The interface state belongs only to that person and has no purpose after erasure.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }

    public function view(): BelongsTo
    {
        return $this->belongsTo(TaskView::class, 'view_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
