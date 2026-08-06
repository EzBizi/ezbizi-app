<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last TOTP time-step this account successfully used.
 *
 * A six-digit code stays valid for the whole 30-second step, and the verifier
 * accepts the adjacent steps for clock drift — so without this the same digits
 * work for roughly 90 seconds and can be presented more than once. RFC 6238 §5.2
 * requires the verifier to refuse a code it has already accepted, and this column
 * is what lets it.
 *
 * It matters here more than most places: the enrolment hand-off has one person
 * reading their code aloud to another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->unsignedBigInteger('last_totp_timestamp')->nullable()->after('totp_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->dropColumn('last_totp_timestamp');
        });
    }
};
