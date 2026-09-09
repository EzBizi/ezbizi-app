<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fifth option now defaults ON (owner, 2026-09-09, relayed by the deploy
 * desk): a test nobody configures counts every retake toward `max_attempts`,
 * the passing attempt included.
 *
 * This is his second answer pointing the same way — "yes it should spend a
 * cap slot", then "do it then" to the default question put to him with the
 * consequence spelled out. `off` is the status quo and there is nothing to
 * "do" about the status quo, so the sentence only attaches to flipping it on.
 *
 * 2026_09_09_170000 added the column with `default(false)` and has RUN in
 * production, so it is never edited — a change made to a spent file silently
 * never applies. This is a new file, per the house rule.
 *
 * ── Existing rows move too, and that is deliberate ──────────────────────
 *
 * A default governs new rows only, so flipping it alone would leave two
 * cohorts of tests behaving differently with nothing in the UI able to
 * explain why — the column has no "unset" state, so a test written yesterday
 * is indistinguishable from one an author deliberately set to `false`. Every
 * row that exists today holds `false` because it was created BEFORE this
 * option had an answer, which makes them precisely the "tests nobody
 * configures" the owner was asked about. Moving them is the faithful reading
 * of his answer, not an extra liberty taken with it.
 *
 * `down()` restores the default and deliberately does NOT move rows back: it
 * cannot know which of them were `false` by choice rather than by age, and
 * inventing that distinction is worse than leaving the data where the
 * upgrade put it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_tests', function (Blueprint $table) {
            $table->boolean('count_attempts_after_pass')->default(true)->change();
        });

        DB::table('training_tests')->where('count_attempts_after_pass', false)->update([
            'count_attempts_after_pass' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('training_tests', function (Blueprint $table) {
            $table->boolean('count_attempts_after_pass')->default(false)->change();
        });
    }
};
