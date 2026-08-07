<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a company is and how to reach it (plan §3, §5.2).
 *
 * A company is a legal-ish entity — the thing whose name and address end up on
 * an invoice (§5.2) — so it needs both. Until now it had a name and nothing
 * else, which was enough while nobody could create one from the UI.
 *
 * ── The same shape as a customer's address ───────────────────────
 *
 * Deliberately the same five columns, in the same order, with the same lengths.
 * Two address formats in one system means two parsers, two validators and two
 * ways to print a label — and the second one is always the one that gets a
 * field wrong.
 *
 * A franchise still has none. It is a geography (§3): "Connecticut" is not
 * somewhere you can post a letter, and giving it an address would invite people
 * to put the company's there and then wonder which one an invoice used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('address_line1', 200)->nullable()->after('name');
            $table->string('address_line2', 200)->nullable()->after('address_line1');
            $table->string('city', 100)->nullable()->after('address_line2');
            $table->string('state', 2)->nullable()->after('city');
            $table->string('postal_code', 12)->nullable()->after('state');
            $table->string('phone', 40)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn([
            'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'phone',
        ]));
    }
};
