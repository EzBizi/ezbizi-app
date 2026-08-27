<?php

namespace App\Models;

use App\Jobs\DeliverTaskNotificationIntent;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class TaskNotificationIntent extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    public const MAX_ATTEMPTS = 10;

    protected $fillable = ['board_id', 'task_id', 'recipient_user_id', 'kind', 'source_occurrence_key', 'available_at'];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'claimed_until' => 'datetime', 'attempts' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(function (self $intent): void {
            $id = $intent->id;
            DB::afterCommit(fn () => DeliverTaskNotificationIntent::dispatch($id));
        });
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Content-free delivery identity remains as idempotency and delivery-state evidence for task notifications.');
    }

    public static function personalDataPurpose(): string
    {
        return 'A content-free task notification delivery intent addressed to one person.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('recipient_user_id, kind, source_occurrence_key, status, available_at, claimed_until, attempts, last_error_code', PersonalDataEntry::ACTIVITY, 'The intended recipient and content-free delivery lifecycle and occurrence identity.', Erasure::drop('The intent is addressed to exactly one person and has no purpose after that recipient is erased.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('recipient_user_id', $subject->getKey()) : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
