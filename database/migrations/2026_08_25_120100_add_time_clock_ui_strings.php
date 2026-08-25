<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clock in copy (design §11). English and Spanish ship as rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $en = [
            'time_clock.clock_in' => 'Clock in',
            'time_clock.clock_out' => 'Clock out',
            'time_clock.tab' => 'Clock in–out',
            'time_clock.empty' => 'Nobody clocked in on this day.',
            'time_clock.times_in' => 'Times in {zone}',
            'time_clock.in' => 'In',
            'time_clock.off' => 'Clock in is switched off for this organization.',
            'time_clock.already_in' => 'You are already clocked in.',
            'time_clock.not_in' => 'You are not clocked in.',
        ];

        $es = [
            'time_clock.clock_in' => 'Entrada',
            'time_clock.clock_out' => 'Salida',
            'time_clock.tab' => 'Entrada y salida',
            'time_clock.empty' => 'Nadie registró entrada este día.',
            'time_clock.times_in' => 'Horas en {zone}',
            'time_clock.in' => 'Dentro',
            'time_clock.off' => 'Entrada está desactivada para esta organización.',
            'time_clock.already_in' => 'Ya registró su entrada.',
            'time_clock.not_in' => 'No ha registrado entrada.',
        ];

        $rows = [];
        foreach (['en' => $en, 'es' => $es] as $locale => $strings) {
            foreach ($strings as $key => $text) {
                $rows[] = [
                    'locale' => $locale,
                    'key' => $key,
                    'text' => $text,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('ui_strings')->insert($rows);
    }

    public function down(): void
    {
        DB::table('ui_strings')->whereIn('key', [
            'time_clock.clock_in',
            'time_clock.clock_out',
            'time_clock.tab',
            'time_clock.empty',
            'time_clock.times_in',
            'time_clock.in',
            'time_clock.off',
            'time_clock.already_in',
            'time_clock.not_in',
        ])->delete();
    }
};
