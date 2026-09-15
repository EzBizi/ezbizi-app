<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call set down somewhere anybody can pick it up again.
 *
 * ── Why two columns and not a parking-slot table ─────────────────
 *
 * RingCentral models this as Park Locations: numbered slots you park into and
 * dial back to retrieve. Slots exist because a desk phone has buttons and a
 * button needs a number. Nothing here has buttons — the screen can list the
 * calls themselves, by who is on them, which is the thing somebody actually
 * wants to know before picking one up.
 *
 * So there is no slot to allocate, run out of, or collide on. A parked call is
 * a call with a time on it, and its queue is named after its own id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->timestamp('parked_at')->nullable()->after('dealt_at');
            $table->uuid('parked_by')->nullable()->after('parked_at');

            // Read on every poll of the parked list, and the list is drawn
            // beside a live call — so this is a hot small query, which is
            // exactly what an index is for.
            $table->index(['connection_id', 'parked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropIndex(['connection_id', 'parked_at']);
            $table->dropColumn(['parked_at', 'parked_by']);
        });
    }
};
