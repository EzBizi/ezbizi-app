<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password resets, for both kinds of account (plan §4, §4.2).
 *
 * Deliberately not Laravel's `password_reset_tokens`, which is keyed by EMAIL
 * alone. That does not work here for two reasons:
 *
 *   One address may exist in several organizations (§4.3) — separate accounts,
 *   separate passwords — so an email is not an account.
 *
 *   Staff and users are different tables, and a linked account's password lives
 *   on the STAFF row (§4.2, revised). A reset has to name which record it is
 *   resetting, or it will confidently change the wrong one.
 *
 * So a token points at a subject, and the subject is whatever actually owns the
 * password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('subject_type');
            $table->uuid('subject_id');

            // HASHED, like a password. The plaintext exists only in the link that
            // was sent; a leak of this table must not hand somebody a working
            // reset for every account that has one outstanding.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            // Kept for the audit trail: "who asked for this" is the first
            // question after an unexpected reset.
            $table->string('requested_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
