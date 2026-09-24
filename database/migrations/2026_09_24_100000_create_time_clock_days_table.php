<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Day records for Work Hours: a tag, extra minutes, a note (Jose, 2026-09-24).
 *
 * See App\Models\TimeClockDay for why these live on the DAY rather than on a
 * shift — four of the six tags describe days nobody clocked in on.
 *
 * Also the copy for the six tags, the day actions and the lock screen's
 * after-hours sentence, in English and Spanish.
 */
return new class extends Migration
{
    private const KEYS = [
        'time_clock.tag.office', 'time_clock.tag.home', 'time_clock.tag.absent',
        'time_clock.tag.sick', 'time_clock.tag.holiday', 'time_clock.tag.vacation',
        'time_clock.after_hours',
    ];

    public function up(): void
    {
        Schema::create('time_clock_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // The day in the ORGANIZATION'S calendar — the same one Work Hours
            // groups shifts by — not a UTC instant.
            $table->date('date');
            $table->string('tag', 16)->nullable();
            $table->unsignedSmallInteger('extra_minutes')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['organization_id', 'date']);
        });

        $now = now();
        $en = [
            'time_clock.tag.office' => 'Office',
            'time_clock.tag.home' => 'Home',
            'time_clock.tag.absent' => 'Absent',
            'time_clock.tag.sick' => 'Sick',
            'time_clock.tag.holiday' => 'Holiday',
            'time_clock.tag.vacation' => 'Vacation',
            'time_clock.after_hours' => 'Your day ended at {end}. Clocking in adds {minutes} minutes.',
        ];
        $es = [
            'time_clock.tag.office' => 'Oficina',
            'time_clock.tag.home' => 'Casa',
            'time_clock.tag.absent' => 'Ausente',
            'time_clock.tag.sick' => 'Enfermo',
            'time_clock.tag.holiday' => 'Feriado',
            'time_clock.tag.vacation' => 'Vacaciones',
            'time_clock.after_hours' => 'Tu jornada termino a las {end}. Registrar entrada suma {minutes} minutos.',
        ];

        $rows = [];
        foreach (['en' => $en, 'es' => $es] as $locale => $strings) {
            foreach ($strings as $key => $text) {
                $rows[] = ['locale' => $locale, 'key' => $key, 'text' => $text, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('ui_strings')->insert($rows);
    }

    public function down(): void
    {
        DB::table('ui_strings')->whereIn('key', self::KEYS)->delete();
        Schema::dropIfExists('time_clock_days');
    }
};
