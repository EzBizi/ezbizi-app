<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tech expenses (Merav via Jose, 2026-11-19): a part the technician bought
 * with his own money, to reimburse and later add to payroll. `paid_at` is
 * when it was marked, kept across the parts rewrite; `reimbursed_at` when
 * he was paid back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_parts', function (Blueprint $table) {
            $table->uuid('paid_by_user_id')->nullable()->after('late_notified_at');
            $table->timestamp('paid_at')->nullable()->after('paid_by_user_id');
            $table->timestamp('reimbursed_at')->nullable()->after('paid_at');
            $table->index(['paid_by_user_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('job_parts', function (Blueprint $table) {
            $table->dropIndex(['paid_by_user_id', 'paid_at']);
            $table->dropColumn(['paid_by_user_id', 'paid_at', 'reimbursed_at']);
        });
    }
};
