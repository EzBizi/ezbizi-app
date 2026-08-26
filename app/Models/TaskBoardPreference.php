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

class TaskBoardPreference extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'user_id', 'is_favorite', 'last_opened_at'];

    protected function casts(): array
    {
        return ['is_favorite' => 'boolean', 'last_opened_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The current personal board preference remains until the user or organization deliberately removes it; no automated expiry exists.');
    }

    public static function personalDataPurpose(): string
    {
        return 'One user personal favorite and last-opened state for a task board.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('user_id, is_favorite, last_opened_at', PersonalDataEntry::ACTIVITY, 'Who saved the board and when they most recently opened it.', Erasure::drop('This row is only one person private interface state and has no meaning after that person is erased.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
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
