<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Book panel takes that a job had nowhere to keep (Jose, 2026-10-27:
 * "I want all the options on Book"): where the work came from, the branch's own
 * custom fields, an all-day visit, and a repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('job_source', 100)->nullable()->after('job_type');
            $table->json('custom_fields')->nullable()->after('needs_second_visit');
            $table->boolean('all_day')->default(false)->after('duration_minutes');
            $table->json('recurrence')->nullable()->after('all_day');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['job_source', 'custom_fields', 'all_day', 'recurrence']);
        });
    }
};
