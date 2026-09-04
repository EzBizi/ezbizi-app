<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where somebody's consent to be texted a verification code is recorded.
 *
 * ── Why not `users.phone` ────────────────────────────────────────
 *
 * That column already holds a phone number, and it is the wrong one to text.
 * It is a profile field — a desk line, a number an administrator typed in
 * during onboarding, sometimes a landline — and nobody agreed to anything by
 * having it filled in. Sending codes there would be sending to a number that
 * was never opted in, which is the precise thing carriers police.
 *
 * So the number that receives codes is entered separately, by the person who
 * owns the phone, on the screen that also takes the consent. They are usually
 * the same digits and they are never the same fact.
 *
 * ── Why the consent has its own timestamp ────────────────────────
 *
 * Twilio's toll-free verification, and every carrier audit after it, asks WHEN
 * and HOW consent was given. A boolean answers neither. The timestamp is the
 * record that this account, on this date, ticked a box that was not
 * pre-ticked — and it is written in the same statement as the number, so a
 * number can never exist here without one.
 *
 * ── Why confirmed is separate from consent ───────────────────────
 *
 * Consent is what the person agreed to. Confirmation is proof the number
 * reaches them: a code sent and typed back. A number that is consented but
 * unconfirmed is a typo away from texting a stranger, so nothing is sent to it
 * beyond the one confirmation code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // E.164, the way every other number in this database is stored
            // since 2026_09_03_180000.
            $table->string('sms_code_number', 20)->nullable()->after('phone');
            $table->timestamp('sms_code_consent_at')->nullable()->after('sms_code_number');
            $table->timestamp('sms_code_confirmed_at')->nullable()->after('sms_code_consent_at');

            // Short-lived, hashed like a password because it is one for the
            // minutes it lives. A code readable in the database is a code
            // anybody with a backup can use.
            $table->string('sms_code_hash')->nullable()->after('sms_code_confirmed_at');
            $table->timestamp('sms_code_expires_at')->nullable()->after('sms_code_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'sms_code_number',
                'sms_code_consent_at',
                'sms_code_confirmed_at',
                'sms_code_hash',
                'sms_code_expires_at',
            ]);
        });
    }
};
