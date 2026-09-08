<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The job a lead became.
 *
 * Booking a lead creates a customer and a job in EzBizi's own Jobs (Jose,
 * 2026-09-08, item 2 of the a1tools list). The lead keeps the job's id so the
 * thread header can say "Booked · Job 0142 · Thu 2 PM" and open it, and so
 * the work can be attributed back to the advertising that produced it.
 *
 * nullOnDelete: a job deleted later leaves the lead as it was, booked in
 * status and pointing at nothing, which is the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignUuid('job_id')
                ->nullable()
                ->after('connection_id')
                ->constrained('jobs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_id');
        });
    }
};
