<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read is shared now (Jose, 2026-10-05: "make the read or unread shared for
 * everyone instead of individual"): one mark on the lead, whoever opened it.
 * Stars stay personal in lead_reads. Backfilled from the newest personal mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('status');
        });

        DB::statement('UPDATE leads JOIN (SELECT lead_id, MAX(read_at) AS m FROM lead_reads '
            .'WHERE read_at IS NOT NULL GROUP BY lead_id) r ON r.lead_id = leads.id SET leads.read_at = r.m');
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
