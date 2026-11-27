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

/**
 * A person asking for paid days off, and the answer (Jose, 2026-10-26).
 * `days` is [{date, portion}]; approving writes them to pto_used.
 */
class PtoRequest extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its person's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['people', User::class, 'user_id'];

    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['organization_id', 'user_id', 'days', 'total', 'note', 'status',
        'decided_by_user_id', 'decided_at', 'decision_reason'];

    protected function casts(): array
    {
        return ['days' => 'array', 'total' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public static function personalDataPurpose(): string
    {
        return 'Requests for paid time off: the days asked for, the note, and the decision.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Kept while the account exists, like the rest of the employment record.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'The employee asking for the paid days off.',
                Erasure::drop('Removed with the person: a request for time off is theirs alone.')),
            new PersonalDataEntry('days', PersonalDataEntry::ACTIVITY,
                'The days asked for, each a full or half day.',
                Erasure::drop('Removed with the person: the dates are about their time off.')),
            new PersonalDataEntry('note', PersonalDataEntry::CONTENT,
                'What the employee wrote with the request, which can name a reason.',
                Erasure::drop('Removed with the person: the note is about their time off.')),
            new PersonalDataEntry('decision_reason', PersonalDataEntry::CONTENT,
                'Why the request was approved or denied, as the approver wrote it.',
                Erasure::drop('Removed with the person: the reason is about their time off.')),
            new PersonalDataEntry('decided_by_user_id', PersonalDataEntry::IDENTITY,
                'The colleague who approved or denied the request, shown beside it.',
                Erasure::keep('A foreign key to the approver\'s account, which the purge '
                    .'anonymises at its one home.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }
}
