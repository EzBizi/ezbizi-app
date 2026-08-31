<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two settings a line needs and could not express.
 *
 * `recording_notice` — the sentence spoken before a recorded call connects. It
 * was hardcoded to "This call may be recorded." for every organization in
 * every jurisdiction. That sentence is what discharges all-party consent, so a
 * business that needs different wording — naming itself, or saying why — had
 * no way to say it and was recording anyway. Null keeps the built-in default,
 * so nothing changes for anyone who does not set it.
 *
 * `fallback_number` — where a call goes when the steps above it resolve to
 * nobody. Today that call becomes voicemail silently; a business with an
 * answering service or an owner's mobile has nowhere to name it. Distinct from
 * `after_hours_number`, which only applies when a schedule says CLOSED: this
 * one covers the case where the line is open and still reaches nobody.
 *
 * Additive, nullable, no backfill, no triggers, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('recording_notice', 500)->nullable()->after('record');
            $table->string('fallback_number', 16)->nullable()->after('after_hours_number');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['recording_notice', 'fallback_number']);
        });
    }
};
