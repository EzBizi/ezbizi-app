<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Callers a company has had enough of.
 *
 * ── Why a company holds this and not a line ─────────────────────
 *
 * Somebody blocks a caller because that caller is a nuisance, not because
 * they are a nuisance on one number. A per-line list would mean blocking the
 * same robocaller once per line and finding out you missed one when it rings
 * the whole ring group at seven in the morning. `connection_id` is the
 * company, so one entry covers every line the company answers.
 *
 * ── Why the number is stored E.164 and matched exactly ──────────
 *
 * Twilio hands us E.164 on the webhook, so an exact match on a normalised
 * column is the whole comparison. Anything cleverer — prefixes, area codes,
 * patterns — is a way to block a customer by accident, and there is nothing
 * to tell you that happened: a call that never rings leaves no one wondering
 * why the phone was quiet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_blocked_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')
                ->constrained('phone_connections')
                ->cascadeOnDelete();

            $table->string('e164', 32);

            // Why, in the words of whoever blocked it. Optional, and worth
            // having: six months on, a bare number tells you nothing about
            // whether unblocking it is safe.
            $table->string('reason', 200)->nullable();

            $table->foreignUuid('blocked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // One entry per caller per company. Without this, blocking twice
            // makes two rows and unblocking removes one, so the caller stays
            // blocked and the screen says they are not.
            $table->unique(['connection_id', 'e164']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_blocked_numbers');
    }
};
