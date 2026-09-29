<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One availability pool for several areas (Eve, 2026-09-29: "to be able to
 * give 2 service areas, the same availability pool").
 *
 * An area that shares points at the LEAD area whose week, dates and limits it
 * uses; bookings in the lead and in every area pointing at it count against
 * that one pool. No chains: a lead never shares itself (the API refuses it).
 * Deleting the lead gives each member its own week back, empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->uuid('shares_with_id')->nullable()->after('available_from');
            $table->foreign('shares_with_id')->references('id')->on('service_areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropForeign(['shares_with_id']);
            $table->dropColumn('shares_with_id');
        });
    }
};
