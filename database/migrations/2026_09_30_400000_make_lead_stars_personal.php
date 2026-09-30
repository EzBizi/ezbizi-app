<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A star is each person's own, like the read mark beside it (Jose,
 * 2026-09-30: "the star system seems shared between everyone instead of being
 * personal"). `lead_reads` already holds one row per person per lead, so the
 * star joins it; read_at becomes nullable because a lead can be starred from
 * the list without being opened. `leads.starred_at` is left in place and no
 * longer read - nobody knows whose stars those were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_reads', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->change();
            $table->timestamp('starred_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_reads', function (Blueprint $table) {
            $table->dropColumn('starred_at');
        });
    }
};
