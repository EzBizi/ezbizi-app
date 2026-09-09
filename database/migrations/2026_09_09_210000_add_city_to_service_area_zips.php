<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A zip carries the city it is in
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §2).
 *
 * A NEW FILE rather than an edit to 2026_08_31_130000, which has already run
 * on production: Laravel keys by filename and skips a spent file, so a column
 * added there would never be created on any box that already migrated it, and
 * every test would still pass because a fresh database runs the file whole.
 *
 * Nullable, because the zips already stored have no city and inventing one
 * would be making up coverage data. A zip with no city is still a zip — the
 * city is a second way to FIND an area, not a requirement for having one.
 *
 * The index is on (franchise_id, city) and is NOT unique. Many zips share a
 * city, which is the entire point: "Dallas" has to reach all of them. Only the
 * ZIP is unique per franchise, and that constraint stays exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->string('city', 120)->nullable()->after('zip');

            $table->index(['franchise_id', 'city']);
        });
    }

    public function down(): void
    {
        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->dropIndex(['franchise_id', 'city']);
            $table->dropColumn('city');
        });
    }
};
