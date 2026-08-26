<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Tasks\BoardMemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskBoardMember extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'user_id', 'role', 'is_active', 'archived_at'];

    protected function casts(): array
    {
        return ['role' => BoardMemberRole::class, 'is_active' => 'boolean', 'archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Membership history is retained with the board so private-board access and ownership decisions remain explainable.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Which user belongs to a task board and whether they are an owner or member.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('user_id, role, is_active, archived_at', PersonalDataEntry::ACTIVITY, 'The person membership, role, current state, and archive time for this board.', Erasure::keep('The account is anonymised at its source while this access history remains necessary to explain private-board ownership.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
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
