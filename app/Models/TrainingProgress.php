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
 * How far one user has gotten through one guide.
 */
class TrainingProgress extends Model implements HoldsPersonalData
{
    use HasUuids;
    use BelongsToTenant;

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'How far one person has read into one guide, and whether they finished it. '
            .'It is what tells an organization somebody has actually seen material they are '
            .'relying on that person having seen.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A progress row only means something next to the guide it tracks reading '
            .'through: it is created against that guide and removed by the cascade the '
            .'moment the guide is deleted, so nothing here outlives what it is progress '
            .'THROUGH. Unlike an attempt, there is no separate evidentiary reason to keep '
            .'it once the guide itself is gone — a passed test still proves something years '
            .'later, a reading position in a deleted guide does not.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which person this reading progress belongs to.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan a progress row from the '
                    .'person it is about without removing anything about them.')),
            new PersonalDataEntry('last_screen, completed_at', PersonalDataEntry::ACTIVITY,
                'How far they have read, and when — if ever — they finished.',
                Erasure::keep('The position is the record. Blanking it would leave a row '
                    .'saying somebody was reading a guide without saying how far, which is '
                    .'worse than a position kept against an already-anonymised account.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'user_id', 'guide_id', 'last_screen', 'completed_at',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'guide_id');
    }
}
