<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Copy for asking for, and answering, a Work Hours correction (2026-09-30). */
return new class extends Migration
{
    private const EN = [
        'time_clock.action.request' => 'Request a correction',
        'time_clock.request_reason' => 'What happened?',
        'time_clock.request_send' => 'Send for approval',
        'time_clock.request_sent' => 'Sent for approval.',
        'time_clock.requests_title' => 'Correction requests',
        'time_clock.request_was' => 'Was',
        'time_clock.request_asks' => 'Asks for',
        'time_clock.approve' => 'Approve',
        'time_clock.decline' => 'Decline',
    ];

    private const ES = [
        'time_clock.action.request' => 'Solicitar una corrección',
        'time_clock.request_reason' => '¿Qué pasó?',
        'time_clock.request_send' => 'Enviar para aprobación',
        'time_clock.request_sent' => 'Enviado para aprobación.',
        'time_clock.requests_title' => 'Solicitudes de corrección',
        'time_clock.request_was' => 'Antes',
        'time_clock.request_asks' => 'Solicita',
        'time_clock.approve' => 'Aprobar',
        'time_clock.decline' => 'Rechazar',
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
