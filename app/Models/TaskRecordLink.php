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

class TaskRecordLink extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['board_id', 'task_id', 'record_type', 'record_id', 'display_snapshot', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['display_snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Governed record links and their allowlisted snapshots remain with current and archived tasks until deliberately unlinked or redacted.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Connects a task to another governed EzBizi record and caches only that registry declaration allowlisted display snapshot.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('record_type, record_id, display_snapshot', PersonalDataEntry::CONTENT, 'Opaque target identity plus the allowlisted display snapshot, which can name a customer, worker, or other person.', Erasure::keep('The target registry owns reauthorization and redaction; the snapshot remains until that declaration privacy handler removes or redacts it.')),
            new PersonalDataEntry('created_by', PersonalDataEntry::ACTIVITY, 'The person who created the governed link.', Erasure::keep('The referenced account is anonymised at its source while link history remains.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('created_by', $subject->getKey()) : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
