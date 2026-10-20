<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google's daily counts per listing - calls, website clicks, directions,
 * messages, views on Search and Maps (A1 Leads takeover, 2026-10-20). Kept
 * here so a report is a query, and so history outlives Google's window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_location_metrics', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('business_location_id');
            $table->date('date');
            $table->string('metric', 48);
            $table->unsignedInteger('value');

            $table->unique(['business_location_id', 'date', 'metric'], 'blm_location_date_metric');
            $table->index(['organization_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('business_location_id')->references('id')->on('business_locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_location_metrics');
    }
};
