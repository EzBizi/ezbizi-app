<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two PTO numbers gained a unit, so their keys stopped describing them.
 *
 * `pto.days_per_year` cannot hold "half a day a month" without lying in its own
 * name, and `pto.waiting_days` cannot hold three months. This renames the rows
 * rather than leaving them behind: the settings table is keyed by string, so a
 * definition renamed in the registry and nowhere else would silently read its
 * default and an organization would find its policy back at zero.
 *
 * Nothing is converted. The number somebody typed stays the number, and the new
 * unit rows are left unset so they resolve to the registry defaults — year and
 * days, which is what every existing value already meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'pto.days_per_year')->update(['key' => 'pto.allowance']);
        DB::table('settings')->where('key', 'pto.waiting_days')->update(['key' => 'pto.waiting']);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'pto.allowance')->update(['key' => 'pto.days_per_year']);
        DB::table('settings')->where('key', 'pto.waiting')->update(['key' => 'pto.waiting_days']);
    }
};
