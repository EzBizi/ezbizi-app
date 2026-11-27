<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review QR (Jose, 2026-11-23): a code a technician shows on the spot, tied to the branch's reviews page and
 * to the person who made it, so scans, faces and forms count for that technician. No customer behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_codes', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->change();
            $table->uuid('user_id')->nullable()->after('job_id');
            $table->uuid('franchise_id')->nullable()->after('user_id');
            $table->index(['user_id', 'franchise_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->cascadeOnDelete();
        });
        Schema::table('review_link_events', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->change();
            $table->char('review_code', 7)->nullable()->collation('utf8mb4_bin')->after('job_id');
            $table->uuid('user_id')->nullable()->after('review_code');
            $table->index(['organization_id', 'user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('review_link_events', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'user_id', 'occurred_at']);
            $table->dropColumn(['review_code', 'user_id']);
        });
        Schema::table('review_codes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['franchise_id']);
            $table->dropIndex(['user_id', 'franchise_id']);
            $table->dropColumn(['user_id', 'franchise_id']);
        });
    }
};
