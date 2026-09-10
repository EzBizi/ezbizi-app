<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rule can run on a clock, not only on something happening (plan §5.5).
 *
 * ── Two columns, and why not six ─────────────────────────────────
 *
 * `schedule` holds the whole answer as JSON — kind, time, weekdays or
 * day-of-month, and the zone the time was TYPED in. Six typed columns would say
 * the same thing while making "weekly" and "monthly" each carry the other's
 * unused column, and every later shape a migration. `users.work_schedule`
 * already stores a schedule this way in this application.
 *
 * `next_run_at` is the one thing that must be a real indexed column, because it
 * is what the per-minute sweep queries: `where next_run_at <= now()`. Deriving
 * it from the JSON at query time would mean parsing every rule in every
 * organization once a minute to find the handful that are due.
 *
 * ── Why the instant is stored and not the wall clock ─────────────
 *
 * `next_run_at` is UTC, computed by resolving the typed wall-clock IN ITS OWN
 * ZONE. That is what makes daylight saving come out right: 9am America/New_York
 * is a different instant in January and in July, and re-resolving on each
 * advance produces both. Storing a fixed offset instead would bake one of them
 * in forever — which is exactly what the organization's default `Etc/GMT+5`
 * does, and why the zone is stored per rule rather than read from a setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->json('schedule')->nullable()->after('trigger_key');

            // Nullable because most rules are event-driven and have no next run;
            // the index is what keeps the sweep from reading them anyway.
            $table->timestamp('next_run_at')->nullable()->after('schedule');
            $table->index('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropIndex(['next_run_at']);
            $table->dropColumn(['schedule', 'next_run_at']);
        });
    }
};
