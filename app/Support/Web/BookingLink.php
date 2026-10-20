<?php

namespace App\Support\Web;

use App\Models\BookingCode;
use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * A booking page link that names one lead (Jose, 2026-10-13: after hours a
 * written lead gets the branch's booking link). The widget sends the token back
 * with the booking, so it lands on that lead instead of making a second one,
 * and skips asking for what the lead already holds.
 *
 * Short (Jose, 2026-10-20, "like we did with the review links"): the link is
 * https://<booking page's site>/b/<7 base62 chars>, a random code in
 * booking_codes for the lead; the site's /b/ forwards to its booking page
 * with ?l=<code>. The older signed lead.expires.mac tokens still work.
 */
final class BookingLink
{
    /** How long the link works. */
    public const DAYS = 14;

    /** e.g. https://www.angisweep.com/b/Ab3xK9m, on the booking page's own site. */
    public static function forLead(Lead $lead, string $bookingPage): string
    {
        $parts = parse_url($bookingPage);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/b/'.self::code($lead);
    }

    /** The lead id a code or token names, or null when it was not made here or has expired. */
    public static function leadId(?string $token): ?string
    {
        if (is_string($token) && preg_match('/^[A-Za-z0-9]{7}$/', $token) === 1) {
            $id = BookingCode::query()->whereKey($token)->where('expires_at', '>=', now())->value('lead_id');

            return $id === null ? null : (string) $id;
        }

        $pieces = is_string($token) ? explode('.', $token) : [];

        if (count($pieces) !== 3 || ! ctype_digit($pieces[1]) || (int) $pieces[1] < now()->timestamp) {
            return null;
        }

        return hash_equals(self::mac($pieces[0].'.'.$pieces[1]), $pieces[2]) ? $pieces[0] : null;
    }

    /** The lead's code: the one already sent while it has a week left, else a new random one. */
    public static function code(Lead $lead): string
    {
        $existing = BookingCode::query()->where('lead_id', $lead->getKey())
            ->where('expires_at', '>', now()->addDays(7))->value('code');

        if (is_string($existing)) {
            return $existing;
        }

        do {
            $code = Str::random(7);
        } while (BookingCode::query()->withoutGlobalScopes()->whereKey($code)->exists());

        BookingCode::create(['code' => $code, 'lead_id' => $lead->getKey(), 'expires_at' => now()->addDays(self::DAYS)]);

        return $code;
    }

    /** The older signed token: lead.expires.mac. Still read by leadId; no longer minted. */
    public static function signed(Lead $lead): string
    {
        $body = $lead->getKey().'.'.now()->addDays(self::DAYS)->timestamp;

        return $body.'.'.self::mac($body);
    }

    private static function mac(string $body): string
    {
        return substr(hash_hmac('sha256', 'booking-lead:'.$body, (string) config('app.key')), 0, 24);
    }
}
