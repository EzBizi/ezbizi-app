<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class TaskUpdateVersion extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids, WrittenOnce;

    public const UPDATED_AT = null;

    protected $fillable = ['board_id', 'task_id', 'update_id', 'body', 'body_plain', 'edited_by', 'created_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Immutable update revisions remain with the task so edits never silently replace history.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Previous update text and the person who replaced it.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('body, body_plain', PersonalDataEntry::CONTENT, 'A prior formatted and plain-text task update.', Erasure::keep('The revision is the durable edit history and free text cannot be reliably attributed to one subject.')),
            new PersonalDataEntry('edited_by', PersonalDataEntry::ACTIVITY, 'The person who created this revision while editing an update.', Erasure::keep('The referenced account is anonymised at its source while edit accountability remains.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('edited_by', $subject->getKey()) : null;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Task update revisions are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Task update revisions are immutable.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskUpdate(): BelongsTo
    {
        return $this->belongsTo(TaskUpdate::class, 'update_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
