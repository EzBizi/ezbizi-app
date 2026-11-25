<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every phone number a person typed, stored the way Twilio and the app compare
 * them: `+13055550107`.
 *
 * The client started formatting phone fields on 2026-09-03 and normalising what
 * they send, so everything typed from now on arrives as E.164. This is the
 * other half: rows already in the database from before that, which display
 * unformatted because `formatPhoneNumber` only recognises the stored form.
 *
 * Measured on production before writing this — 19 rows across six columns are
 * not E.164, and they are two different problems:
 *
 *   9 rows are a real number written another way
 *       customers `305-555-0199`, users `3055550107`, companies, vendors
 *
 *   10 rows are not a phone number at all
 *       business_locations and leads hold `+1 (555) 0120` — EIGHT digits.
 *       Seeded demo data. There is no NANP number that could be, and the
 *       missing digits cannot be recovered.
 *
 * ── What it will not do ──────────────────────────────────────────
 *
 * Only a value whose digits are exactly ten, or eleven beginning with 1, is
 * rewritten. Everything else is left exactly as it is: the eight-digit rows
 * above, extensions, and any international number somebody entered on purpose.
 *
 * Inventing the two missing digits of `+1 (555) 0120` would produce a number
 * that dials a stranger, and it would look correct doing it. A row that stays
 * visibly wrong can be corrected by the person who owns it; one that is
 * silently wrong cannot be found again.
 *
 * NANP also forbids an area code or exchange starting 0 or 1, so a ten-digit
 * value breaking that is not a US number written loosely — it is something
 * else, and it is left alone too.
 *
 * ── Why there is no down() ───────────────────────────────────────
 *
 * `+13055550107` and `305-555-0199` are the same number, so nothing is lost
 * and there is nothing to restore. A down() that put the punctuation back
 * would have to invent which punctuation, and would be undoing a correction
 * rather than a change.
 */
return new class extends Migration
{
    /**
     * table => column. `e164`, `from_e164` and `to_e164` are deliberately
     * absent: those are written by Twilio in E.164 already, and rewriting a
     * column the carrier owns is how a call record stops matching its own call.
     *
     * `leads.phone_digits` is absent for the same reason in reverse — it is
     * derived from `phone`, and no lead row changes here, because every
     * non-conforming lead is one of the eight-digit ones.
     */
    private const COLUMNS = [
        'customers' => 'phone',
        'users' => 'phone',
        'companies' => 'phone',
        'inventory_vendors' => 'phone',
        'business_locations' => 'phone',
        'leads' => 'phone',
        'vehicle_insurance_policies' => 'agent_phone',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            // dropIfExists' cousin: a fresh install may not have every module's
            // table yet, and a migration that dies on a missing one blocks the
            // whole deploy for a column that holds nothing.
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $digits = "REGEXP_REPLACE(`{$column}`, '[^0-9]', '')";

            // Ten digits -> +1XXXXXXXXXX. Eleven starting with 1 are the same
            // number carrying its country code, so they land identically.
            $national = "CASE
                WHEN CHAR_LENGTH({$digits}) = 11 AND LEFT({$digits}, 1) = '1'
                    THEN SUBSTRING({$digits}, 2)
                ELSE {$digits}
            END";

            DB::statement(
                "UPDATE `{$table}`
                 SET `{$column}` = CONCAT('+1', {$national})
                 WHERE `{$column}` IS NOT NULL
                   AND `{$column}` <> ''
                   AND CHAR_LENGTH({$national}) = 10
                   AND SUBSTRING({$national}, 1, 1) NOT IN ('0', '1')
                   AND SUBSTRING({$national}, 4, 1) NOT IN ('0', '1')
                   AND `{$column}` <> CONCAT('+1', {$national})"
            );
        }
    }

    public function down(): void
    {
        // Deliberately nothing — see the note above.
    }
};
