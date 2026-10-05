<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editing your own hours carries no mark — for Jose only (2026-10-05, asked
 * whether it should be anyone who can edit hours: "only to me").
 *
 * A per-person switch rather than his id in the code, so the rule is data
 * like `lock_screen_exempt` beside it. Switched on here for his account on
 * A1-Group; a no-op anywhere that account does not exist. Every OTHER
 * person's edits of their own shifts, unmarked by 2026_10_01_120000, read
 * "Edited" again.
 */
return new class extends Migration
{
    private const JOSE = '019fd712-75d3-711e-86f1-596d39292c51';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('time_clock_edits_unmarked')->default(false)->after('lock_screen_exempt');
        });

        DB::table('users')->where('id', self::JOSE)->update(['time_clock_edits_unmarked' => true]);

        DB::table('time_clock_shifts')
            ->whereNull('edit_source')
            ->where('user_id', '!=', self::JOSE)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('audit_events')
                ->whereColumn('audit_events.target_id', 'time_clock_shifts.id')
                ->whereColumn('audit_events.actor_id', 'time_clock_shifts.user_id')
                ->where('audit_events.action', 'time_clock.corrected'))
            ->update(['edit_source' => 'hr']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('time_clock_edits_unmarked');
        });
    }
};
