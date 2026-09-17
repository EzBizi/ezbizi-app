<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three sentences the one-day list owned, and nothing can render now.
 *
 * Work Hours replaced that screen (`70c1331c`), so its empty state, its
 * timezone caption and its word for a shift still running have no call site
 * left in the client.
 *
 * ── Why only three of the nine unused keys ───────────────────────
 *
 * `time_clock.already_in`, `not_in`, `off` and `not_scheduled` are also
 * unreferenced, and they STAY. They are the four refusal sentences: the server
 * emits them hardcoded in English and the client shows what the server said,
 * so nothing reads the rows — but they are the only Spanish those sentences
 * have, and deleting them would make a later i18n pass start from nothing.
 * Unused is not dead. `time_clock.auto` and `time_clock.outside` stay for a
 * plainer reason: the new screen is about to use them.
 */
return new class extends Migration
{
    private const RETIRED = [
        'time_clock.empty',
        'time_clock.times_in',
        'time_clock.in',
    ];

    public function up(): void
    {
        DB::table('ui_strings')->whereIn('key', self::RETIRED)->delete();
    }

    public function down(): void
    {
        $now = now();

        $en = [
            'time_clock.empty' => 'Nobody clocked in on this day.',
            'time_clock.times_in' => 'Times in {zone}',
            'time_clock.in' => 'In',
        ];

        $es = [
            'time_clock.empty' => 'Nadie registro entrada este dia.',
            'time_clock.times_in' => 'Horas en {zone}',
            'time_clock.in' => 'Dentro',
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
};
