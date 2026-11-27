<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desk 11-19: "live" was read from available_at - changed_at, and a retry moves available_at,
 * so a live create that hit Monday's budget once waited 45 min behind 15.7k backfill rows.
 * A backfill row now says so when queued; retries never change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monday_outbox', function (Blueprint $table) {
            $table->boolean('backfill')->default(false)->after('kinds');
        });
        // Pending rows queued to wait (never retried): the backfills already in the queue.
        if (DB::getDriverName() === 'mysql') {
            DB::table('monday_outbox')->where('state', 'pending')->where('attempts', 0)
                ->whereRaw('available_at > changed_at + INTERVAL 5 MINUTE')->update(['backfill' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('monday_outbox', fn (Blueprint $table) => $table->dropColumn('backfill'));
    }
};
