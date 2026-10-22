<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The weekly Repair Team report's approval and the values typed in where the
// sheet left one out (Jose: the "WhatsApp Shoval Report").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_repair_weeks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->json('overrides')->nullable(); // {team: {field: dollars}}
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['franchise_id', 'week_start']);
            $table->index(['organization_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_repair_weeks');
    }
};
