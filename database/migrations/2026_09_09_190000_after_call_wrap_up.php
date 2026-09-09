<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A moment after a call before the next one arrives.
 *
 * ── What it is for ──────────────────────────────────────────────
 *
 * The call ends and there is work left: write the note, pick the tag, book
 * the follow-up. With nothing in the way the next call in the queue arrives
 * while that is half done, so it gets abandoned — and the company's own rule
 * that every call must be tagged turns into a dialogue somebody dismisses.
 *
 * ── Why a deadline and not a state machine ──────────────────────
 *
 * `wrap_up_until` is a TIME, and availability is read from it. A state that
 * had to be cleared by something — a job, a timer, a callback from a client
 * that may have been closed — is a state that gets stuck, and a person stuck
 * in wrap-up is a person the ring group silently stops calling. That exact
 * failure was reported on this module today, on the on-call icon, which is
 * why this is a deadline that expires on its own whether or not anything
 * runs.
 *
 * Zero seconds on the connection means off, which is what every company gets
 * until somebody chooses otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->unsignedSmallInteger('wrap_up_seconds')
                ->default(0)
                ->after('require_call_tag');
        });

        Schema::table('phone_presences', function (Blueprint $table) {
            $table->timestamp('wrap_up_until')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->dropColumn('wrap_up_seconds');
        });

        Schema::table('phone_presences', function (Blueprint $table) {
            $table->dropColumn('wrap_up_until');
        });
    }
};
