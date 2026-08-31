<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a post should go out, in the clock the person was looking at.
 *
 * ── Why two columns and not an instant ───────────────────────────
 *
 * TODO #20: wall clock, not a moment in time. "Tuesday at 9am" scheduled from
 * the Connecticut branch means Connecticut's 9am — and an instant computed at
 * compose time would drift if that branch's timezone were ever corrected, or
 * if the platform's fixed-offset policy ever gained real DST. Storing the wall
 * time and the clock it belongs to keeps the promise the person actually made,
 * and the dispatcher resolves it fresh each minute.
 *
 * `scheduled_for` is naive — `Y-m-d H:i:s` with no zone — for the same reason
 * `FranchiseWallTime` stores jobs that way. `scheduled_timezone` is the name of
 * the clock, captured from the view at compose time, because the view can be
 * switched afterwards and the post must not move when it is.
 *
 * ── Why not on the target ────────────────────────────────────────
 *
 * One post, one time. The accounts it goes to are ticked together and go
 * together; a per-account schedule is a different feature ("stagger these") and
 * inventing the column now would leave every row answering a question nobody
 * asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('scheduled_for')->nullable()->after('title');
            $table->string('scheduled_timezone')->nullable()->after('scheduled_for');
        });

        Schema::table('social_post_targets', function (Blueprint $table) {
            /*
             * Indexed on status alone, deliberately: the dispatcher's question
             * is "which targets are still waiting for their time", and the
             * scheduled set is tiny next to everything ever sent. Comparing the
             * wall time cannot be indexed anyway — it is resolved per row
             * against its own timezone — so narrowing to `scheduled` first is
             * what keeps that scan small.
             */
            $table->index(['status', 'social_post_id'], 'social_post_targets_status_post_index');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropIndex('social_post_targets_status_post_index');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['scheduled_for', 'scheduled_timezone']);
        });
    }
};
