<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Clock in-out" becomes "Work Hours", and the words that screen needs.
 *
 * Jose, 2026-09-17: the tab is about hours worked, not about the act of
 * punching, and it belongs second — beside Dashboard rather than at the end.
 */
return new class extends Migration
{
    private const ADDED = [
        'time_clock.total',
        'time_clock.days_worked',
        'time_clock.average_per_day',
        'time_clock.search_people',
        'time_clock.no_hours',
        'time_clock.this_week',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('ui_strings')->where('locale', 'en')->where('key', 'time_clock.tab')
            ->update(['text' => 'Work Hours', 'updated_at' => $now]);
        DB::table('ui_strings')->where('locale', 'es')->where('key', 'time_clock.tab')
            ->update(['text' => 'Horas de trabajo', 'updated_at' => $now]);

        $en = [
            'time_clock.total' => 'Total',
            'time_clock.days_worked' => 'Days worked',
            'time_clock.average_per_day' => 'Average per day',
            'time_clock.search_people' => 'Search people',
            'time_clock.no_hours' => 'Nothing clocked this week.',
            'time_clock.this_week' => 'This week',
        ];

        $es = [
            'time_clock.total' => 'Total',
            'time_clock.days_worked' => 'Dias trabajados',
            'time_clock.average_per_day' => 'Promedio por dia',
            'time_clock.search_people' => 'Buscar personas',
            'time_clock.no_hours' => 'No se registro nada esta semana.',
            'time_clock.this_week' => 'Esta semana',
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
        DB::table('ui_strings')->where('locale', 'en')->where('key', 'time_clock.tab')
            ->update(['text' => 'Clock in'.chr(0xE2).chr(0x80).chr(0x93).'out']);
        DB::table('ui_strings')->where('locale', 'es')->where('key', 'time_clock.tab')
            ->update(['text' => 'Entrada y salida']);

        DB::table('ui_strings')->whereIn('key', self::ADDED)->delete();
    }
};
