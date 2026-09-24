<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The words on Work Hours' row and day menus (Jose, 2026-09-24, layout 1 and 1:
 * a ⋯ menu on each shift and each day, a bottom sheet on a phone).
 *
 * A new file rather than an addition to `2026_09_24_100000`, which has already
 * run on production and so will never run again there.
 */
return new class extends Migration
{
    private const EN = [
        'time_clock.action.clock_out_now' => 'Clock out now',
        'time_clock.action.edit' => 'Edit times',
        'time_clock.action.delete' => 'Delete shift',
        'time_clock.action.tag' => 'Set tag',
        'time_clock.action.extra' => 'Extra hours',
        'time_clock.action.note' => 'Note',
        'time_clock.shift_actions' => 'Shift actions',
        'time_clock.day_actions' => 'Day actions',
        'time_clock.delete_confirm' => 'Delete this shift? This cannot be undone.',
        'time_clock.extra_time' => 'Extra time',
        'time_clock.no_tag' => 'No tag',
        'time_clock.hours' => 'Hours',
        'time_clock.minutes' => 'Minutes',
        'time_clock.edited' => 'Edited',
        'time_clock.ended_by_hr' => 'Ended by HR',
    ];

    private const ES = [
        'time_clock.action.clock_out_now' => 'Registrar salida ahora',
        'time_clock.action.edit' => 'Editar horas',
        'time_clock.action.delete' => 'Eliminar turno',
        'time_clock.action.tag' => 'Etiqueta',
        'time_clock.action.extra' => 'Horas extra',
        'time_clock.action.note' => 'Nota',
        'time_clock.shift_actions' => 'Acciones del turno',
        'time_clock.day_actions' => 'Acciones del día',
        'time_clock.delete_confirm' => '¿Eliminar este turno? No se puede deshacer.',
        'time_clock.extra_time' => 'Tiempo extra',
        'time_clock.no_tag' => 'Sin etiqueta',
        'time_clock.hours' => 'Horas',
        'time_clock.minutes' => 'Minutos',
        'time_clock.edited' => 'Editado',
        'time_clock.ended_by_hr' => 'Cerrado por RR. HH.',
    ];

    public function up(): void
    {
        $now = now();
        $rows = [];
        foreach (['en' => self::EN, 'es' => self::ES] as $locale => $strings) {
            foreach ($strings as $key => $text) {
                $rows[] = ['locale' => $locale, 'key' => $key, 'text' => $text, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        DB::table('ui_strings')->insert($rows);
    }

    public function down(): void
    {
        DB::table('ui_strings')->whereIn('key', array_keys(self::EN))->delete();
    }
};
