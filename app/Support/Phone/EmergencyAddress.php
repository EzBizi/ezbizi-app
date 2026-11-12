<?php

namespace App\Support\Phone;

use App\Models\BusinessLocation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Every line gets its 911 address without anybody typing one (Desk for Jose,
 * 2026-11-12): the branch's address on file, else its company's, registered
 * with Twilio (which validates it) and attached as the line's emergency
 * address. A line that already has one is left alone.
 */
class EmergencyAddress
{
    public const NO_ADDRESS = 'E911: missing — no address on the branch or company.';

    /** @return array{customer_name: string, street: string, city: string, region: string, postal_code: string, iso_country: string}|null */
    public static function for(PhoneNumber $number): ?array
    {
        $franchise = $number->scope_type === PhoneNumber::SCOPE_FRANCHISE ? Franchise::query()->find($number->scope_id) : null;
        $companyId = $franchise?->company_id ?? ($number->scope_type === PhoneNumber::SCOPE_COMPANY ? $number->scope_id : null);
        $company = $companyId === null ? null : Company::query()->find($companyId);

        $branch = $franchise === null ? null : BusinessLocation::query()->where('franchise_id', $franchise->id)->whereNotNull('address_line1')->first();
        $place = $branch ?? (filled($company?->address_line1) ? $company : null)
            ?? ($company === null ? null : BusinessLocation::query()->where('company_id', $company->id)->whereNotNull('address_line1')->first());

        if ($place === null || blank($place->city) || blank($place->state) || blank($place->postal_code)) {
            return null;
        }

        return [
            'customer_name' => (string) ($company?->name ?? $number->label),
            'street' => trim($place->address_line1.' '.($place->address_line2 ?? '')),
            'city' => (string) $place->city,
            'region' => (string) $place->state,
            'postal_code' => (string) $place->postal_code,
            'iso_country' => (string) ($place->country ?? 'US') ?: 'US',
        ];
    }

    /** @return string|null why it could not be attached; null when done or nothing to do */
    public static function attach(PhoneNumber $number, TwilioGateway $twilio): ?string
    {
        if ($number->emergency_address_set || blank($number->twilio_sid) || $number->connection === null) {
            return null;
        }
        $address = self::for($number);
        if ($address === null) {
            return self::NO_ADDRESS;
        }

        try {
            $sid = collect($twilio->listAddresses($number->connection))->first(fn (array $a) => $a['emergency_enabled']
                && strcasecmp($a['street'], $address['street']) === 0
                && $a['postal_code'] === $address['postal_code'])['sid'] ?? null;
            $sid ??= $twilio->createAddress($number->connection, $address)['sid'];
            $twilio->updateIncoming($number->connection, (string) $number->twilio_sid, ['emergencyAddressSid' => $sid]);
        } catch (Throwable $e) {
            $why = 'E911: Twilio refused the address on file ('.$e->getMessage().').';
            // ponytail: cached, not a column; the hourly retry rewrites it, so it never outlives the refusal.
            Cache::put(self::refusalKey($number), $why, now()->addHours(2));

            return $why;
        }

        Cache::forget(self::refusalKey($number));

        $number->forceFill(['emergency_address_sid' => $sid, 'emergency_address_set' => true])->save();

        return null;
    }

    /** Twilio's last refusal for this line, so health says why instead of promising a retry. */
    public static function refusal(PhoneNumber $number): ?string
    {
        return Cache::get(self::refusalKey($number));
    }

    private static function refusalKey(PhoneNumber $number): string
    {
        return 'phone-e911-refused:'.$number->id;
    }
}
