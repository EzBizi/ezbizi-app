<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskUpdate extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasAttachments, HasUuids;

    protected $fillable = ['board_id', 'task_id', 'author_user_id', 'body', 'body_plain', 'idempotency_key', 'archived_at', 'archived_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Updates and their archived history remain with the task business record.');
    }

    public static function personalDataPurpose(): string
    {
        return 'The task conversation, its author, and archive history.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('body, body_plain', PersonalDataEntry::CONTENT, 'Formatted and plain-text update content that can contain personal details.', Erasure::keep('Free text is part of the durable work conversation and cannot be reliably attributed to one subject.')),
            new PersonalDataEntry('author_user_id, archived_by, archived_at', PersonalDataEntry::ACTIVITY, 'Who wrote or archived the update and when it was archived.', Erasure::keep('Referenced accounts are anonymised at their source while the task history remains intact.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where(fn (Builder $query) => $query->where('author_user_id', $subject->getKey())->orWhere('archived_by', $subject->getKey())) : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TaskUpdateVersion::class, 'update_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(TaskUpdateMention::class, 'update_id');
    }
}
