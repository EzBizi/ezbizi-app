<?php

namespace App\Support\Workiz;

/**
 * Workiz sends a state NAME; `customers.state` is two characters.
 *
 * Found by a test and it would have failed on every single row in production:
 * `customers.state` is `string(2)` and Workiz's real payloads carry "Ohio",
 * "New Jersey", "Massachusetts" — 20 characters at the longest. Not one row
 * would have projected.
 *
 * ── THE MAP IS MEASURED, NOT ASSUMED ────────────────────────────
 *
 * Built from the 38 distinct values actually present in A-1's 7,793 stored
 * Workiz records, which contained two surprises a US-states map would have
 * dropped:
 *
 *  - **`OH` — already a code.** The data is mixed, so anything that is already
 *    two characters passes straight through rather than failing to match a
 *    name.
 *  - **`Ontario` — not a US state at all.** Canadian provinces are in the map
 *    for that reason. A-1 is not as domestic as `Country` being `US` on every
 *    row suggests.
 *
 * The full US set is here rather than only the 38 seen, because the next branch
 * A-1 opens is a state nobody has measured yet, and a map that covers only what
 * has already happened fails silently on growth.
 *
 * An unrecognised value becomes NULL rather than a truncation. "Oh" for Ohio is
 * worse than nothing: it is wrong in a way that looks right, and the full
 * address survives on the customer regardless.
 */
final class StateCode
{
    /** @var array<string, string> */
    private const CODES = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
        'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
        'district of columbia' => 'DC', 'washington dc' => 'DC', 'washington d.c.' => 'DC',
        'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
        'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
        'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
        'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
        'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE',
        'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ',
        'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC',
        'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR',
        'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
        'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
        'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA',
        'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
        'puerto rico' => 'PR',

        // Present in A-1's real data. Ontario appears on live rows.
        'alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB',
        'new brunswick' => 'NB', 'newfoundland and labrador' => 'NL',
        'nova scotia' => 'NS', 'ontario' => 'ON', 'prince edward island' => 'PE',
        'quebec' => 'QC', 'saskatchewan' => 'SK',
    ];

    public static function from(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Already a code — the data is mixed, `OH` is in it.
        if (mb_strlen($value) === 2) {
            return mb_strtoupper($value);
        }

        return self::CODES[mb_strtolower($value)] ?? null;
    }
}
