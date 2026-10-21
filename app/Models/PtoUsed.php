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
 * Paid days off somebody has already used, entered by HR (Jose, 2026-10-21).
 * Subtracted from what PtoPolicy says they earned.
 */
class PtoUsed extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'pto_used';

    protected $fillable = ['organization_id', 'user_id', 'days', 'used_on', 'note', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['used_on' => 'date', 'days' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Paid days off an employee has already used, with the day and a note.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept while the account exists, like the rest of the employment record.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'The employee who used the paid days off.',
                Erasure::drop('Time off is about one person; erasing them removes it.')),
            new PersonalDataEntry('used_on', PersonalDataEntry::ACTIVITY,
                'The day they were off, when HR recorded one.',
                Erasure::drop('Removed with the person: a day off belongs to whoever took it.')),
            new PersonalDataEntry('note', PersonalDataEntry::CONTENT,
                'What HR wrote about the days off, which can name a reason.',
                Erasure::drop('Removed with the person: the note is about their time off.')),
            new PersonalDataEntry('created_by_user_id', PersonalDataEntry::IDENTITY,
                'The colleague who recorded the days as used, shown beside them.',
                Erasure::keep('A foreign key to the author\'s account, which the purge '
                    .'anonymises at its one home.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
