<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Results from an outside lead source (A-1's ACE first; Jose, 2026-10-27):
 * one row per date and area with calls, jobs, cost, sales and profit, and the
 * photo it came from. A row sent over WhatsApp waits as pending and counts
 * only once somebody approves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 60);
            $table->date('date');
            $table->string('area', 120)->nullable();
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('jobs')->default(0);
            $table->bigInteger('cost_cents')->default(0);
            $table->bigInteger('sales_cents')->default(0);
            $table->bigInteger('profit_cents')->default(0);
            $table->string('image_disk', 32)->nullable();
            $table->string('image_path')->nullable();
            $table->char('image_sha256', 64)->nullable();
            $table->string('channel', 16)->default('app'); // app | whatsapp
            $table->string('status', 16)->default('pending'); // pending | approved
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_results');
    }
};
