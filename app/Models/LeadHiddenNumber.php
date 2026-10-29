<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * "Always hide this number" (Jose, 2026-10-29): every lead from it, now and
 * later, is kept from everyone without `leads.hidden.view`.
 */
class LeadHiddenNumber extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'digits', 'hidden_by_user_id'];

    /** The last ten digits, the way leads compare phones; null when there are fewer. */
    public static function digitsOf(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /** Is this phone hidden in the current tenant? For intake that files a message before it is a lead (Meta's WhatsApp, too). */
    public static function covers(?string $phone): bool
    {
        $digits = self::digitsOf($phone);

        return $digits !== null && self::query()->where('digits', $digits)->exists();
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A number the business asked never to show to its staff. Kept until somebody unhides '
            .'it; unhiding deletes the row.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So private conversations on a business line stay out of Leads for staff who may not see them.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('digits', PersonalDataEntry::CONTACT,
                'The hidden phone number, its last ten digits.',
                Erasure::drop('The row is only the number; without it there is nothing to keep.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
