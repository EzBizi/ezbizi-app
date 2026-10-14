<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales pipeline (Jose, 2026-10-14): the salesperson's own columns on a quoted
 * job, replacing the sales executives' spreadsheet. Everything else on the row
 * (customer, quote, job #) is read from the job, so it is never copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('job_id')->unique();
            $table->string('stage', 20)->default('follow_up');
            $table->string('urgency', 20)->nullable();
            $table->date('next_follow_up')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->uuid('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->cascadeOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_deals');
    }
};
