<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fifth per-test option (fifth-option brief, 2026-09-09): whether a
 * retake taken while the learner's existing pass is still valid spends one
 * of their `max_attempts`.
 *
 * The owner's report: somebody who already passed at 81% and wants a better
 * score "burns an attempt from an allowance meant for people who failed."
 * His ruling — "up for the company to decide on the training settings" —
 * makes this a per-test option in the builder, exactly like
 * `requires_guide`/`pass_mark`/`max_attempts`/`valid_for_days`
 * (`create_training_tables`, 2026_08_29), never a platform constant. That
 * migration has already run in production and is never edited — a column
 * added to a spent file silently never applies — so this is a NEW file.
 *
 * Default `false`: a retake taken while a pass is still valid does NOT
 * count, which is the lenient (new) behaviour. `true` is today's behaviour,
 * preserved exactly, for an author who wants every attempt to count no
 * matter what. See `LearnController::submit`'s own docblock for how the
 * cap-check reads this, and `LapsedPasses` for the one definition of
 * "still valid" it is built on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_tests', function (Blueprint $table) {
            $table->boolean('count_attempts_after_pass')->default(false)->after('valid_for_days');
        });
    }

    public function down(): void
    {
        Schema::table('training_tests', function (Blueprint $table) {
            $table->dropColumn('count_attempts_after_pass');
        });
    }
};
