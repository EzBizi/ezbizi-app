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
 * Who to call if something happens to somebody (Jose, 2026-09-29).
 *
 * Personal data about a THIRD person — the contact never signed anything with
 * this organization — so it is kept only as long as the employee's account and
 * dropped whole on erasure.
 */
class EmergencyContact extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'user_id', 'name', 'relationship', 'phone'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Who to call if something happens to an employee at work, as they or HR recorded it.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept while the employee\'s account exists: it is only useful while they might need it.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Whose emergency contact this is.',
                Erasure::drop('The contact is somebody else\'s personal data, held only on the '
                    .'employee\'s behalf; with the employee erased there is no reason to keep it.')),
            new PersonalDataEntry('name, relationship, phone', PersonalDataEntry::CONTACT,
                'The contact\'s name, how they are related, and their phone number.',
                Erasure::drop('Removed with the row: the details were only ever held on the '
                    .'employee\'s behalf.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
