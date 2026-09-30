<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work Hours › General lists only who clocked in that week, latest first
 * (Jose, 2026-09-30); this button shows the rest.
 */
return new class extends Migration
{
    private const TEXT = [
        'en' => [
            'time_clock.show_everyone' => 'Show {count} who did not clock in',
            'time_clock.only_clocked_in' => 'Only who clocked in',
        ],
        'es' => [
            'time_clock.show_everyone' => 'Mostrar {count} que no registraron entrada',
            'time_clock.only_clocked_in' => 'Solo quienes registraron entrada',
        ],
    ];

    public function up(): void
    {
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
    }
};
