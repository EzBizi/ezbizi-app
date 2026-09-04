<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a call failed, kept instead of discarded.
 *
 * ── What this cost ──────────────────────────────────────────────
 *
 * Outbound calling was reported broken four times across two days, and each
 * round was a guess: the voicemail answering itself, then deploy lag, then the
 * desktop stub, then an unchecked caller ID. Every one of those was a real
 * defect and none of them was reached by evidence — because the system threw
 * the evidence away.
 *
 * Twilio DOES say why. `ErrorCode` arrives on the status callback and
 * `DialCallStatus` on the dial's action request, and the module read both and
 * kept neither: the error code only to decide whether the whole LINE was
 * broken (a caller-ID fault is not, so it was dropped), and the dial status
 * only to choose which branch to render.
 *
 * So a failed call left a row saying "failed" and nothing saying why, and the
 * only remaining way to find out was Twilio's own console — which is a place
 * the customer cannot go and the product should not need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            // Twilio's numeric code — 21210 and friends. Kept as a string
            // because it is an identifier that happens to be digits, and
            // because a missing code must be distinguishable from zero.
            $table->string('error_code', 16)->nullable()->after('status');

            // Twilio's own sentence where it sends one. Not composed from the
            // code, so a code we have never seen still explains itself.
            $table->string('error_message', 255)->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn(['error_code', 'error_message']);
        });
    }
};
