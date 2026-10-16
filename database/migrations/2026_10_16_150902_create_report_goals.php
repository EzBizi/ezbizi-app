<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The weekly and monthly report's goals, per organization and company (ReportGoals). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // The P&L company code; null is every company.
            $table->string('company', 16)->nullable();
            $table->string('key', 40);
            $table->decimal('value', 12, 4);
            $table->timestamps();
            $table->unique(['organization_id', 'company', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_goals');
    }
};
