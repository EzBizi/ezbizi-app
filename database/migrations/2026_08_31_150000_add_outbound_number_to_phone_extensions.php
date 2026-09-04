<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number a person calls OUT from.
 *
 * ── Why this hangs off phone_extensions ──────────────────────────
 *
 * That table already holds exactly one row per person per phone account, and
 * it already answers "how does the phone system know who this is" — the
 * extension is the inbound half of that question and the outbound number is
 * the other half. A second table keyed the same way would be two places to
 * look for one person's phone identity, and they would drift the first time
 * somebody was removed from one and not the other.
 *
 * ── Why `extension` becomes nullable ─────────────────────────────
 *
 * Because the two halves are independent. Somebody can be given a number to
 * call out from without being reachable on a short code, and requiring an
 * extension to hold a number would force made-up digits onto every person who
 * only needed the one. MySQL permits repeated NULLs in a unique index, so
 * `unique(connection_id, extension)` still forbids two people sharing 101
 * while allowing any number of people to have none.
 *
 * ── Why the number is NOT unique per person ──────────────────────
 *
 * Several people calling out from one main line is the ordinary case for a
 * small business — a shop with one published number and four staff. Making
 * this unique would model a rule nobody asked for and break the commonest
 * setup on the day it was introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_extensions', function (Blueprint $table) {
            // Deleting a number must not delete the person's row: they keep
            // their extension and simply stop having a number to call from,
            // which is recoverable. Cascading would silently take the
            // extension with it.
            $table->foreignUuid('number_id')
                ->nullable()
                ->after('user_id')
                ->constrained('phone_numbers')
                ->nullOnDelete();
        });

        Schema::table('phone_extensions', function (Blueprint $table) {
            $table->string('extension', 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('phone_extensions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('number_id');
        });
    }
};
