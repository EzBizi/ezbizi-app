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

class TaskUpdateAttachmentCandidate extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'task_id', 'attachment_id', 'actor_user_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere('the locked hourly expired-candidate cleanup', 'Unclaimed staging metadata is removed after its 24-hour expiry; claimed rows are removed in the successful update transaction.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Private temporary ownership metadata for an attachment awaiting claim by a task update.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('attachment_id, actor_user_id, expires_at', PersonalDataEntry::ACTIVITY, 'The staged attachment, its uploader, and when the private candidate expires.', Erasure::drop('The temporary row is removed with the staged attachment or by expiry cleanup.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('actor_user_id', $subject->getKey()) : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
