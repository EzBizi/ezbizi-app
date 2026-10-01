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

/**
 * An address geocoded once. Keyed on a hash of the address TEXT, so a customer
 * who moves is geocoded again, and no column is added to `customers`.
 */
class FieldGeocode extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const OK = 'ok';

    public const NOT_FOUND = 'not_found';

    protected $fillable = ['organization_id', 'address_hash', 'address', 'latitude', 'longitude', 'status'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }

    public static function hash(string $address): string
    {
        return hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $address))));
    }

    /** The one-line address Google is asked about, or null when there is no street to place. */
    public static function addressOf(Customer|Company $customer): ?string
    {
        // A company's headquarters has the same five fields (Field map, 2026-10-01).
        if (trim((string) $customer->address_line1) === '') {
            // A city alone lands mid-town, which is a route to the wrong place.
            return null;
        }

        return implode(', ', array_filter(array_map('trim', [
            (string) $customer->address_line1,
            (string) $customer->address_line2,
            (string) $customer->city,
            trim($customer->state.' '.$customer->postal_code),
        ])));
    }

    public static function personalDataPurpose(): string
    {
        return 'Where a customer\'s address is on the map, so a route can be drawn to it.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'updated_at',
            'A cache of Google\'s answer. Asking again every three months costs a fraction '
            .'of a cent and keeps addresses nobody visits any more from piling up.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('address, latitude, longitude', PersonalDataEntry::CONTACT,
                'A customer\'s address and its position.',
                Erasure::drop('A cache; dropping it only means the next route geocodes again.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof Customer || ($address = self::addressOf($subject)) === null) {
            return null;
        }

        return static::query()->where('address_hash', self::hash($address));
    }
}
