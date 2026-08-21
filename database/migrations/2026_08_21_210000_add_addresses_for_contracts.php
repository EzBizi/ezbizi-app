<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses the contract document actually names.
 *
 * ── Two homes, because they are two facts ────────────────────────
 *
 * A person's home address lives on the profile (the one-home rule). The
 * business address lives on the organization — the party the agreement is
 * with — rather than on a contract field that every template would otherwise
 * re-ask. Both merge as tags; neither is copied onto the other.
 *
 * 500 characters is a postal address with a few lines, not an essay. The
 * same cap on both columns so a fact and a core field of the same kind are
 * held to the same width whichever door they come in through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('phone');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('address');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
