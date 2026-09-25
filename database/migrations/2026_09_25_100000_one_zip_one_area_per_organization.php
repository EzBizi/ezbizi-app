<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A zip belongs to ONE service area in the whole organization, not one per
 * franchise.
 *
 * Jose's rule, 2026-08-31: "ur right we should not share zip codes". The first
 * index enforced it per franchise, which left a zip free to sit on two areas in
 * two different branches — and on 2026-09-25 A-1 had 66 such zips. 27 of them
 * were New York copies of zips that already belonged to New Jersey, Connecticut,
 * Boston North, Atlanta, Cleveland, Detroit: the Nationwide call centre tags
 * out-of-state jobs "New York", and a map derived from job history records the
 * mis-tag faithfully. A search by zip then had two answers and no rule for
 * choosing. Jose, asked whether one area anywhere should hold a zip: "yes fix it".
 *
 * PRODUCTION WAS CLEANED FIRST, the same day, before this could run: every
 * duplicate resolved (0 remain in any organization), backup at
 * /var/backups/ezbizi-scheduling/zipfix-backup-20260925-124820.json. So this
 * cannot fail on the box for a duplicate; on a database that still has one it
 * fails LOUDLY, which is the right outcome — the alternative is choosing an
 * area for somebody's zip inside a migration.
 *
 * `(franchise_id, city)` from 2026_09_09 still indexes franchise_id, so the
 * franchise foreign key keeps an index when the old unique goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->unique(['organization_id', 'zip']);
        });

        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->dropUnique(['franchise_id', 'zip']);
        });
    }

    public function down(): void
    {
        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->unique(['franchise_id', 'zip']);
        });

        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'zip']);
        });
    }
};
