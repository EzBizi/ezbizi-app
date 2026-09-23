<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five shifts nothing was ever going to close.
 *
 * Jose, 2026-09-23, from a screenshot of Adeline's week: a shift clocked in at
 * 5:00 PM on the 22nd still reading "still clocked in" on the 23rd, 15 hours
 * later. Measured on production the same morning: FIVE open rows across four
 * days, every one of them with `scheduled_end_at` null, the oldest from the
 * 14th.
 *
 * The cause is upstream and is fixed in `TimeClock::clockIn()`: a shift that
 * started at or after the schedule's end for that day was stamped with a null
 * end, and `CloseEndedShifts` selects `whereNotNull('scheduled_end_at')`. The
 * sweep could not see those rows and never would have. Adeline's own row is
 * the clearest instance — the sweep closed her 8:26 shift at 21:00:02 and she
 * clocked back in at 21:00:10, into the eight-second hole.
 *
 * ── Why 16 rather than the organization's setting ──
 *
 * `time_clock.max_hours` ships in this same change with a default of 16 and no
 * stored rows anywhere, so resolving it per organization here would read the
 * default for every one of them and cost a settings container inside a
 * migration to do it. The hard-coded number is the same answer, and it is also
 * the safer one: a migration that re-derives from a registry inherits whatever
 * that registry says on the day it RUNS, which is not necessarily what it says
 * in the tree it shipped with (Inspection's finding, 2026-09-17).
 *
 * ── What this is honest about ──
 *
 * It does not know when these people actually went home. It gives each row the
 * end the cap would have given it, so the sweep closes it within the minute
 * and credits exactly 16 hours rather than the 40-odd the row had accumulated
 * by sitting open — `clockOut()` clamps a scheduled close to the scheduled
 * end. Sixteen hours is a cap, not a measurement, and the correction system
 * shipping alongside this is how the real number gets set.
 */
return new class extends Migration
{
    private const CAP_HOURS = 16;

    public function up(): void
    {
        DB::table('time_clock_shifts')
            ->whereNull('clocked_out_at')
            ->whereNull('scheduled_end_at')
            ->update([
                'scheduled_end_at' => DB::raw('DATE_ADD(clocked_in_at, INTERVAL '.self::CAP_HOURS.' HOUR)'),
            ]);
    }

    public function down(): void
    {
        /*
         * Only the rows this touched, and only while they are still open: a
         * blanket "null every scheduled_end_at" would take the ends off every
         * live shift in the system and put the hole back for all of them.
         *
         * A row closed by the sweep in between is not reopened. Rolling back a
         * migration does not un-happen a clock-out, and the correction system
         * is the way to undo one of those.
         */
        DB::table('time_clock_shifts')
            ->whereNull('clocked_out_at')
            ->whereRaw('scheduled_end_at = DATE_ADD(clocked_in_at, INTERVAL '.self::CAP_HOURS.' HOUR)')
            ->update(['scheduled_end_at' => null]);
    }
};
