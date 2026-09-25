<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a PERSON decided a job's service area -- NULL while only the machine
 * has (JobServiceArea, via the Workiz projection or the 2026-09-25 backfill).
 *
 * docs/DEVELOPMENT-RULES.md: a recurring sweep never overwrites a field a
 * person has touched, and must be able to tell the two apart. Asked for by the
 * Scheduling session before Workiz wires the live projection: once a sync has
 * reverted a correction, it looks exactly like one nobody made.
 *
 * A timestamp and not Scheduling's zip convention (`source` NULL = a person),
 * because here the VALUE can be NULL too: a person clearing a wrong area and a
 * job nothing has placed would read the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('service_area_chosen_at')->nullable()->after('service_area_id');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('service_area_chosen_at');
        });
    }
};
