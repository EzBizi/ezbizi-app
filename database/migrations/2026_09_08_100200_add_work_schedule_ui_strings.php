<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Schedule, reminder and lock screen copy (design 2026-09-08 §11). */
return new class extends Migration
{
    private const KEYS = [
        'time_clock.reminder_title', 'time_clock.reminder_body', 'time_clock.extend',
        'time_clock.lock_sentence', 'time_clock.sign_out', 'time_clock.auto',
        'time_clock.outside', 'time_clock.day_off', 'time_clock.work_schedule',
        'time_clock.not_scheduled', 'time_clock.overnight', 'time_clock.exempt_on',
        'time_clock.exempt_off',
    ];

    public function up(): void
    {
        $now = now();

        $en = [
            'time_clock.reminder_title' => 'Your shift is ending',
            'time_clock.reminder_body' => 'Your shift ends at {time}. Clock out, or extend it.',
            'time_clock.extend' => 'Extend {minutes} min',
            'time_clock.lock_sentence' => 'Clock in to start your day.',
            'time_clock.sign_out' => 'Sign out',
            'time_clock.auto' => 'Auto',
            'time_clock.outside' => 'Outside schedule',
            'time_clock.day_off' => 'Day off',
            'time_clock.work_schedule' => 'Work schedule',
            'time_clock.not_scheduled' => 'You are not on a scheduled shift.',
            'time_clock.overnight' => 'A shift that crosses midnight is not supported yet.',
            'time_clock.exempt_on' => 'Exempt from lock screen',
            'time_clock.exempt_off' => 'Lock screen applies',
        ];

        $es = [
            'time_clock.reminder_title' => 'Su turno está por terminar',
            'time_clock.reminder_body' => 'Su turno termina a las {time}. Registre la salida o extiéndalo.',
            'time_clock.extend' => 'Extender {minutes} min',
            'time_clock.lock_sentence' => 'Registre su entrada para empezar el día.',
            'time_clock.sign_out' => 'Cerrar sesión',
            'time_clock.auto' => 'Automático',
            'time_clock.outside' => 'Fuera de horario',
            'time_clock.day_off' => 'Día libre',
            'time_clock.work_schedule' => 'Horario de trabajo',
            'time_clock.not_scheduled' => 'No está en un turno programado.',
            'time_clock.overnight' => 'Un turno que cruza la medianoche todavía no está soportado.',
            'time_clock.exempt_on' => 'Exento de la pantalla de bloqueo',
            'time_clock.exempt_off' => 'La pantalla de bloqueo aplica',
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
        DB::table('ui_strings')->whereIn('key', self::KEYS)->delete();
    }
};
