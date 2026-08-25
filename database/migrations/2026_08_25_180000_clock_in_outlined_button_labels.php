<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Top-bar punch labels match the outlined chip: Clock In / Clock Out.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ui_strings')->where('locale', 'en')->where('key', 'time_clock.clock_in')->update(['text' => 'Clock In']);
        DB::table('ui_strings')->where('locale', 'en')->where('key', 'time_clock.clock_out')->update(['text' => 'Clock Out']);
    }

    public function down(): void
    {
        DB::table('ui_strings')->where('locale', 'en')->where('key', 'time_clock.clock_in')->update(['text' => 'Clock in']);
        DB::table('ui_strings')->where('locale', 'en')->where('key', 'time_clock.clock_out')->update(['text' => 'Clock out']);
    }
};
