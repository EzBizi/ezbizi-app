<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The three strings the Work Hours screen still says in English.
 *
 * Found by UI/UX after my own grep missed them: it matched `text:` and
 * `Text(`, which cannot see a `tooltip:`, and I had reported the file clean
 * on the strength of it.
 *
 * ── Why "still clocked in" is a new key and not `time_clock.in` ──
 *
 * `time_clock.in` was the one-day list's terse "In", a value in an out-column,
 * and its Spanish is "Dentro" — inside. That reads as a table cell, not as a
 * sentence on a row, and reusing it would have carried a weak translation into
 * a place it means less. The pip beside the row already carries the terse
 * version; the words can afford to say what they mean. So `time_clock.in`
 * retires as planned in the migration before this one.
 */
return new class extends Migration
{
    private const ADDED = [
        'time_clock.previous_week',
        'time_clock.next_week',
        'time_clock.still_in',
    ];

    public function up(): void
    {
        $now = now();

        $en = [
            'time_clock.previous_week' => 'Previous week',
            'time_clock.next_week' => 'Next week',
            'time_clock.still_in' => 'still clocked in',
        ];

        $es = [
            'time_clock.previous_week' => 'Semana anterior',
            'time_clock.next_week' => 'Semana siguiente',
            'time_clock.still_in' => 'aun en turno',
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
        DB::table('ui_strings')->whereIn('key', self::ADDED)->delete();
    }
};
