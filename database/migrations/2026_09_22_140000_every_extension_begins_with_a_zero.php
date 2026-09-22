<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put a zero in front of the extensions that predate the rule.
 *
 * ── Why the rows have to move and not just the rule ─────────────
 *
 * Jose, 2026-09-22: *"all extensions should also be forced to begin with 0 as
 * in 001 002"*. Measured on production the same day: **nine extensions exist
 * and not one of them starts with a zero** — they are 101 through 109.
 *
 * So enforcing the rule on writes alone would leave every existing person
 * unsaveable. Open the Users tab, change somebody's outbound line, and the
 * save is refused because of an extension they were given weeks ago under a
 * rule that did not exist. A rule the stored data breaks is a rule that
 * punishes whoever next touches a row.
 *
 * ── Why a PREFIX and not a renumber ─────────────────────────────
 *
 * 101 becomes 0101, not 001. Prefixing keeps every person's identity — the
 * number they have told people, with a zero in front — and it cannot collide,
 * because a set of unique strings stays unique when the same character is put
 * in front of all of them. Renumbering to 001, 002 would reassign nine people
 * to digits chosen by whatever order the rows came back in.
 *
 * ── What this does change, and it is real ───────────────────────
 *
 * Nine people are reachable on different digits afterwards. Somebody who
 * dials 103 will be told there is no such extension. That is the cost of the
 * rule Jose asked for, it is the smallest version of it, and `down()` puts
 * every one of them back exactly.
 *
 * Only rows that need it: a `0` prefix is not added twice if this is ever
 * re-run against a row that already has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('phone_extensions')
            ->whereNotNull('extension')
            ->where('extension', '!=', '')
            ->where('extension', 'not like', '0%')
            ->update(['extension' => DB::raw("CONCAT('0', extension)")]);
    }

    public function down(): void
    {
        // Only the ones a zero could have been added to. An extension somebody
        // deliberately created as `0101` after this ran must not be stripped
        // back to `101` — this cannot tell those apart, so it takes the
        // narrower reading and leaves anything already four characters or
        // shorter alone only where the remainder is still a valid extension.
        foreach (DB::table('phone_extensions')->whereNotNull('extension')->get(['id', 'extension']) as $row) {
            if (! str_starts_with((string) $row->extension, '0')) {
                continue;
            }

            $without = substr((string) $row->extension, 1);
            if ($without === '' || ! ctype_digit($without)) {
                continue;
            }

            DB::table('phone_extensions')
                ->where('id', $row->id)
                ->update(['extension' => $without]);
        }
    }
};
