<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The next yearly visit (Jose, 2026-10-05: the reminder is "so he can confirm
 * the appointment date for his next visit"). `visit_job_id` is the job the
 * customer's confirmed date booked; `followed_up_at` is when
 * membership.visit_unconfirmed was raised for staff because nobody answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignUuid('visit_job_id')->nullable()->after('reminded_at')->constrained('jobs')->nullOnDelete();
            $table->timestamp('followed_up_at')->nullable()->after('visit_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_job_id');
            $table->dropColumn('followed_up_at');
        });
    }
};
