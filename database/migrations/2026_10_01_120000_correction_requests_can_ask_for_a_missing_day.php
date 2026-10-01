<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A correction request can ask for a day nobody clocked (Jose, 2026-10-01:
 * Anisa worked the Tuesday of the A1 Tools switch-over and has no shift for
 * it). Such a request has no shift and nothing it "was"; approving it adds
 * the shift. Plus the copy for "Add shift" and "Request hours for this day".
 *
 * And `time_clock_shifts.edit_source`, the same day: "it should say Shift
 * Corrected instead of edited, edited should only be for editions that were
 * not requested". `hr` is an edit nobody asked for, `request` an approved
 * correction, null nothing (or somebody editing their own hours, which he
 * asked to carry no mark). `ended_by` keeps saying only who ENDED a shift.
 */
return new class extends Migration
{
    private const TEXT = [
        'en' => [
            'time_clock.action.add_shift' => 'Add shift',
            'time_clock.action.request_day' => 'Request hours for this day',
            'time_clock.missing_day' => 'Nothing clocked',
            'time_clock.corrected' => 'Shift corrected',
        ],
        'es' => [
            'time_clock.action.add_shift' => 'Agregar turno',
            'time_clock.action.request_day' => 'Solicitar horas de este día',
            'time_clock.missing_day' => 'Nada registrado',
            'time_clock.corrected' => 'Turno corregido',
        ],
    ];

    public function up(): void
    {
        Schema::table('time_clock_correction_requests', function (Blueprint $table) {
            $table->uuid('shift_id')->nullable()->change();
            $table->timestamp('was_in_at')->nullable()->change();
        });

        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->string('edit_source', 16)->nullable()->after('ended_by');
        });

        // What the rows already say: every correction so far was HR's, except
        // the shifts an approved request moved.
        DB::table('time_clock_shifts')->where('ended_by', 'correction')->update(['edit_source' => 'hr']);
        DB::table('time_clock_shifts')
            ->whereIn('id', DB::table('time_clock_correction_requests')->where('status', 'approved')->whereNotNull('shift_id')->select('shift_id'))
            ->update(['edit_source' => 'request']);
        // Somebody's edits of their OWN shifts carry no mark (the audit names
        // who corrected each one).
        DB::table('time_clock_shifts')
            ->where('edit_source', 'hr')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('audit_events')
                ->whereColumn('audit_events.target_id', 'time_clock_shifts.id')
                ->whereColumn('audit_events.actor_id', 'time_clock_shifts.user_id')
                ->where('audit_events.action', 'time_clock.corrected'))
            ->update(['edit_source' => null]);

        $now = now();
        foreach (self::TEXT as $locale => $strings) {
            foreach ($strings as $key => $text) {
                DB::table('ui_strings')->updateOrInsert(
                    ['locale' => $locale, 'key' => $key],
                    ['text' => $text, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('ui_strings')->whereIn('key', array_keys(self::TEXT['en']))->delete();
        DB::table('time_clock_correction_requests')->whereNull('shift_id')->delete();

        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->dropColumn('edit_source');
        });

        Schema::table('time_clock_correction_requests', function (Blueprint $table) {
            $table->uuid('shift_id')->nullable(false)->change();
            $table->timestamp('was_in_at')->nullable(false)->change();
        });
    }
};
