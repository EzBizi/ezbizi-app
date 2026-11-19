<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work Hours › Payroll (Merav via Jose, 2026-11-19): one person's week as a
 * manager signs it off — bonus, deductions, why, and who approved it. Hours
 * and the rate are read live from the shifts and pay_rates, never copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_week_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->text('bonus')->nullable();       // encrypted, like pay_rates.amount
            $table->text('deductions')->nullable();  // encrypted
            $table->text('note')->nullable();        // encrypted
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'week_start']);
            $table->index(['organization_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_week_lines');
    }
};
