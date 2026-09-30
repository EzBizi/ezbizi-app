<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work Hours › General's role filter (Jose, 2026-09-30): its "no filter"
 * choice.
 */
return new class extends Migration
{
    private const TEXT = [
        'en' => ['time_clock.all_roles' => 'All roles'],
        'es' => ['time_clock.all_roles' => 'Todos los roles'],
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
