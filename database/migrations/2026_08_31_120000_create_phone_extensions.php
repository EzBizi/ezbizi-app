<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short number that reaches one person.
 *
 * ── Why a table and not a column on users ───────────────────────
 *
 * An extension belongs to a PHONE ACCOUNT, not to a person. The same user can
 * work for two companies with separate Twilio accounts and be 101 in one and
 * 204 in the other, and "101" must mean one person within an account and may
 * mean somebody else entirely in the next. A column on `users` could express
 * neither, and `users` is shared by every module besides.
 *
 * Unique per (connection, extension) for that reason — the constraint is what
 * makes an extension a usable address rather than a label. Twilio has no
 * concept of extensions at all: to Twilio these are Client identities, and the
 * digits are ours to resolve before we dial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_extensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Digits only, stored as a string: 101 and 0101 are different
            // extensions, and an integer would quietly make them the same.
            $table->string('extension', 8);

            $table->timestamps();

            $table->unique(['connection_id', 'extension']);

            // One extension per person per account. Two would make "call 101"
            // ambiguous in the other direction.
            $table->unique(['connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_extensions');
    }
};
