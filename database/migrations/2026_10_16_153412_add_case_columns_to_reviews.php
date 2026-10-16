<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review as a case to work (Jose, 2026-10-16; UI queue 101): what A1Leads
 * GBP kept on Rodrigo's server, kept here as EzBizi's own data. Its status,
 * the customer and job it is about, whether it was reported to Google, and
 * the notes people leave on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('status', 20)->nullable()->after('rating');
            $table->uuid('customer_id')->nullable()->after('connection_id');
            $table->string('customer_match', 10)->nullable()->after('customer_id');
            $table->uuid('job_id')->nullable()->after('customer_match');
            $table->timestamp('reported_at')->nullable();
            $table->text('report_reason')->nullable();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->nullOnDelete();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('review_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('review_id');
            $table->uuid('user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['review_id', 'created_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('review_id')->references('id')->on('reviews')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_notes');
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['job_id']);
            $table->dropIndex(['organization_id', 'status']);
            $table->dropColumn(['status', 'customer_id', 'customer_match', 'job_id', 'reported_at', 'report_reason']);
        });
    }
};
