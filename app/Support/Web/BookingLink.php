<?php

namespace App\Support\Web;

use App\Models\Lead;

/**
 * A booking page link that names one lead (Jose, 2026-10-13: after hours a
 * written lead gets the branch's booking link). The widget sends the token back
 * with the booking, so it lands on that lead instead of making a second one,
 * and skips asking for what the lead already holds.
 *
 * Ids only, never the phone or ZIP: lead.expires.mac, as CustomerLinks signs.
 */
final class BookingLink
{
    /** How long the link works. */
    public const DAYS = 14;

    /** The booking page with this lead's token, e.g. https://www.a-1chimney.com/booking/?l=… */
    public static function forLead(Lead $lead, string $bookingPage): string
    {
        $body = $lead->getKey().'.'.now()->addDays(self::DAYS)->timestamp;

        return $bookingPage.(str_contains($bookingPage, '?') ? '&' : '?').'l='.rawurlencode($body.'.'.self::mac($body));
    }

    /** The lead id a token names, or null when it was not made here or has expired. */
    public static function leadId(?string $token): ?string
    {
        $pieces = is_string($token) ? explode('.', $token) : [];

        if (count($pieces) !== 3 || ! ctype_digit($pieces[1]) || (int) $pieces[1] < now()->timestamp) {
            return null;
        }

        return hash_equals(self::mac($pieces[0].'.'.$pieces[1]), $pieces[2]) ? $pieces[0] : null;
    }

    private static function mac(string $body): string
    {
        return substr(hash_hmac('sha256', 'booking-lead:'.$body, (string) config('app.key')), 0, 24);
    }
}
