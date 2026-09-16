<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a website stopped calling home, so it is only said once.
 *
 * ── Why a column and not a computed answer ──
 *
 * "Has this site gone quiet" can be derived from `last_seen_at` at any moment,
 * and that is exactly why the sweep needs somewhere to remember that it has
 * already said so. Without it, a site dead for a month raises the same event
 * every time the sweep runs, and the rule Jose writes on it sends him the same
 * message for ever — which is how a useful alert becomes one people filter out.
 *
 * Cleared the moment the site is seen again, in the same write that records the
 * sighting. So the pair reads: `last_seen_at` is the fact, `went_quiet_at` is
 * whether anybody has been told about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->timestamp('went_quiet_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn('went_quiet_at');
        });
    }
};
