<?php

namespace App\Support\Workiz;

/**
 * Workiz sends bare digits; `customers.phone` holds E.164.
 *
 * Jose, 2026-09-23, from the Customers list: "phones are showing without
 * format on customers, which makes it feel like some random number" — rows
 * reading `4242095909` where EzBizi's own customers read `+1 (424) 209-5909`.
 *
 * ── IT IS NOT A DISPLAY BUG, WHICH IS WHY THE FIX IS HERE ────────
 *
 * `formatPhoneNumber()` was already being called at both sites —
 * `customers_screen.dart:352` and `customer_detail_panel.dart:110`. It returned
 * the number unchanged because it is deliberately strict: anything that is not
 * exactly `+1` and ten digits is handed back as it arrived, on the argument
 * that an unformatted number is honest and a wrongly formatted one is not.
 *
 * So the shape in the DATABASE was wrong, and the display was the only symptom
 * anybody could see. Measured on production before writing this:
 *
 *     customers with a phone   632
 *       E.164 (+1XXXXXXXXXX)    15   <- EzBizi's own write path
 *       bare 10 digits         617   <- everything the projection created
 *
 * The cosmetic half is the least of it. `phone_numbers.dart` says the API, the
 * database and Twilio all key on E.164, so those 617 were not merely ugly —
 * they were the wrong value for calling or texting a customer.
 *
 * ── THE MAP IS MEASURED, NOT ASSUMED ────────────────────────────
 *
 * All 617 stored Workiz phones are exactly ten digits with no punctuation and
 * no leading country code — one shape, no surprises, unlike `StateCode` where
 * the data was mixed. The other arms below are for growth rather than for
 * anything seen: a `1`-prefixed eleven, and an already-converted value passing
 * through so a second run is a no-op.
 *
 * ── AN UNRECOGNISED VALUE IS KEPT, NOT NULLED ───────────────────
 *
 * The opposite choice from [StateCode], deliberately. A state that will not map
 * is worth dropping because "Oh" is wrong in a way that looks right. A phone
 * number that will not map is still somebody's phone number, and discarding it
 * loses the only way to reach a customer. So it is returned exactly as it
 * arrived and the client leaves it unformatted — which is the same honesty
 * `formatPhoneNumber()` already practises at the other end.
 */
final class PhoneE164
{
    public static function from(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        // Already ours. A second projection run must not rewrite it.
        if (preg_match('/^\+1[0-9]{10}$/', $value) === 1) {
            return $value;
        }

        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        // `1` plus ten is the same number with the country code spelled out.
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return $value;
        }

        // NANP forbids an area code or exchange starting 0 or 1. The same test
        // `formatPhoneNumber()` applies, for the same reason: a number that
        // breaks it is not a NANP number we should be stamping `+1` onto.
        if ($digits[0] === '0' || $digits[0] === '1' || $digits[3] === '0' || $digits[3] === '1') {
            return $value;
        }

        return '+1'.$digits;
    }
}
