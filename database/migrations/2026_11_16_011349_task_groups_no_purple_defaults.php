<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UI-UX 11-16: no purple in the app. The default groups that still carry
 * their seeded purple take the new defaults; a colour someone picked stays.
 */
return new class extends Migration
{
    private const SWAPS = [
        ['Recurring Tasks', '#A25DDC', '#00A9B5'],
        ['Guidelines', '#784BD1', '#7F5347'],
    ];

    public function up(): void
    {
        foreach (self::SWAPS as [$name, $old, $new]) {
            DB::table('task_groups')->where('name', $name)->where('colour', $old)->update(['colour' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::SWAPS as [$name, $old, $new]) {
            DB::table('task_groups')->where('name', $name)->where('colour', $new)->update(['colour' => $old]);
        }
    }
};
