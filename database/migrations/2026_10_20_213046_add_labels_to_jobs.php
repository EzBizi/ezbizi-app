<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job's type, sub-status and tags, native (Jose, 2026-10-20: "If workiz has
 * tags, we need to have tags"). Words, not ids: the organization's own, as
 * Workiz keeps them, so nothing has to be mapped to move off Workiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('job_type', 100)->nullable()->after('status');
            $table->string('sub_status', 100)->nullable()->after('job_type');
            $table->json('tags')->nullable()->after('sub_status');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['job_type', 'sub_status', 'tags']);
        });
    }
};
