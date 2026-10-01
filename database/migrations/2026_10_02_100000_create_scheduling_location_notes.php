<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One free-text note per company for new dispatchers, shown on Scheduling ›
 * Locations (Jovanni, 2026-10-01: "contact info for newbie dispatchers";
 * design docs/previews/scheduling-locations-layouts.html, queue 49).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_location_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('company_id');
            $table->text('text');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique('company_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_location_notes');
    }
};
