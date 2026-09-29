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
 * A note HR keeps about somebody (Jose, 2026-09-29: "HR notes").
 *
 * A dated list with an author rather than one text box: notes are added over
 * years by different people, and "who wrote this, and when" is half of what a
 * note means.
 */
class HrNote extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'user_id', 'author_user_id', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public static function personalDataPurpose(): string
    {
        return 'Free-text notes HR keeps about an employee, each with its author and date.';
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
                'The employee the note is written about.',
                Erasure::drop('A note is about one person; erasing them removes what was '
                    .'written about them.')),
            new PersonalDataEntry('body', PersonalDataEntry::CONTENT,
                'Whatever HR wrote about the employee, as free text.',
                Erasure::drop('What was written about the person is removed with them, not kept against nobody.')),
            new PersonalDataEntry('author_user_id', PersonalDataEntry::IDENTITY,
                'The colleague who wrote the note, shown beside it.',
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
