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

class TaskActivity extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids, WrittenOnce;

    public const UPDATED_AT = null;

    protected $fillable = ['board_id', 'task_id', 'actor_user_id', 'kind', 'summary', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The immutable human activity feed is durable task history and does not inherit the shorter audit-event window.');
    }

    public static function personalDataPurpose(): string
    {
        return 'A durable human-readable task change history, including the actor and allowlisted change details.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('actor_user_id', PersonalDataEntry::ACTIVITY, 'The person responsible for the meaningful task change.', Erasure::keep('The referenced account is anonymised at its source while business accountability remains.')),
            new PersonalDataEntry('summary, data', PersonalDataEntry::CONTENT, 'The activity summary and structured allowlisted change snapshot, which can include tenant-entered values.', Erasure::keep('The immutable feed is the durable work history; field-specific writers and privacy handling constrain its payload.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('actor_user_id', $subject->getKey()) : null;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Task activities are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Task activities are immutable.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
