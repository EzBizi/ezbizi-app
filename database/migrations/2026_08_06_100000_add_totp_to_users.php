<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor for tenant users (plan §6 — `security.require_two_factor`).
 *
 * The setting has existed in the registry since the settings work; nothing
 * implemented it. This is the other half.
 *
 * Deliberately the same shape as platform_staff, because it is the same problem
 * and the same trait now drives both:
 *
 *   totp_secret          the live one
 *   pending_totp_secret  issued but unproven — a mis-scan must not lock anyone out
 *   totp_confirmed_at    null means the pending one has never been proven
 *   recovery_codes       single-use, shown once
 *   last_totp_timestamp  the step already spent, so a code cannot be replayed
 *
 * The difference is policy, not storage: staff MUST have it, an organization may
 * require it of its users, and otherwise it is theirs to switch on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted at rest, like the staff ones. A database leak that
            // exposes TOTP secrets makes the second factor worthless, and
            // recovery codes are a complete bypass.
            $table->text('totp_secret')->nullable()->after('password');
            $table->text('pending_totp_secret')->nullable()->after('totp_secret');
            $table->timestamp('totp_confirmed_at')->nullable()->after('pending_totp_secret');
            $table->unsignedBigInteger('last_totp_timestamp')->nullable()->after('totp_confirmed_at');
            $table->text('recovery_codes')->nullable()->after('last_totp_timestamp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'totp_secret', 'pending_totp_secret', 'totp_confirmed_at',
                'last_totp_timestamp', 'recovery_codes',
            ]);
        });
    }
};
