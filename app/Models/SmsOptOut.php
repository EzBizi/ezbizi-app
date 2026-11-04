<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A number that texted STOP to one company (SmsConsent). Written and read
 * through SmsConsent; this model is the privacy declaration for the table.
 */
class SmsOptOut extends Model implements HoldsPersonalData
{
    use BelongsToTenant;

    protected $primaryKey = 'e164';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['e164', 'keyword', 'created_at'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A phone number that replied STOP to one company\'s texts, and when. Kept until '
            .'the person replies START: forgetting it is how somebody who opted out starts '
            .'receiving texts again, so the row protects them rather than serving us.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'One row per phone number that asked one company to stop texting it. Every '
            .'text that company sends is checked against it, so an opt-out holds.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry(
                'e164',
                PersonalDataEntry::CONTACT,
                'Refusing a text to a number that replied STOP. Compared, never listed back to a tenant.',
                Erasure::keep(
                    'Deleting it undoes the opt-out and the texts start again. It is kept to '
                    .'honour the request it represents; replying START removes it.'
                ),
            ),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        $phone = $subject->getAttribute('phone');

        if (! is_string($phone) || $phone === '') {
            return null;
        }

        return static::query()
            ->where('organization_id', $subject->getAttribute('organization_id'))
            ->where('e164', $phone);
    }
}
