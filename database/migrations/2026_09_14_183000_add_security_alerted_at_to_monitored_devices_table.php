<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers that `monitoring.security_needs_attention` has already been raised
 * for a machine's current fault, so the automation fires once per fault rather
 * than once per heartbeat — a device beats every 60 seconds, and an alert a
 * minute is not an alert, it is a lesson in ignoring alerts.
 *
 * ── Why a column, having first tried not to have one ──────────────
 *
 * The first version of this compared the incoming security block with the one
 * already stored in `specs`, on the reasoning that the previous beat IS the
 * record and a marker column would be a second source of truth. A test proved
 * that wrong before it shipped: a beat carrying specs but NO security block —
 * an older agent, a Mac, a Windows Server with no Security Center — overwrites
 * `specs` and destroys the very memory the comparison depended on. The next
 * real report then looked like a brand new fault and raised the automation a
 * second time for something nobody had touched.
 *
 * Storing the last-known block instead would have fixed the memory and broken
 * the roster, which renders `specs.security` — a machine that stopped
 * reporting would have gone on showing the state it had when it stopped, with
 * nothing on screen admitting the reading was frozen.
 *
 * So: a marker, next to `offline_alerted_at`, which is the same idea for the
 * same table and already reads as one to anybody who has seen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->timestamp('security_alerted_at')->nullable()->after('offline_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->dropColumn('security_alerted_at');
        });
    }
};
