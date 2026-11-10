<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The times a project manager offers the client before a job is scheduled
 * (Jose, 2026-11-10, PM round 4): {date, slot, alt_date, alt_slot}, asked by
 * "Ready to schedule" and shown in the Ready to Schedule tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->json('schedule_proposal')->nullable()->after('external_company');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('schedule_proposal');
        });
    }
};
