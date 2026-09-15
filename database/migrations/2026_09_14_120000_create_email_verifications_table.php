<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signup email verification (TODO #22).
 *
 * Keyed to a USER, not polymorphically to a subject, which is where this differs
 * from `password_resets` next door. A reset has to name which record owns the
 * password because staff and users are separate tables and a linked account's
 * password lives on the staff row. Verification has no such problem: only signup
 * mints these, signup only ever creates a `User`, and staff accounts are made by
 * `ezbizi:create-staff` rather than by anybody proving they can read a mailbox.
 * A polymorphic column here would be a shape copied for symmetry, carrying a
 * question this table never has to answer.
 *
 * The token is hashed for the same reason the reset token is: the plaintext
 * exists only in the message that was sent, and a leak of this table must not
 * hand somebody a working confirmation for every account awaiting one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // The address the link was SENT to, recorded rather than read back
            // off the user at redemption time. Somebody who signs up with a typo
            // and then corrects their address must not be verified by the link
            // that went to the typo — that link proves control of the wrong
            // mailbox, which is the entire thing being established here.
            $table->string('email');

            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            // "Who asked for this" is the first question after an unexpected
            // confirmation, same as for a reset.
            $table->string('requested_ip', 45)->nullable();

            $table->timestamps();

            // The sweep that finds a user's outstanding token, and the one that
            // expires old rows.
            $table->index(['user_id', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
    }
};
