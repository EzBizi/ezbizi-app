<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An appointment a visitor asked for on a website's booking widget (Jose,
 * 2026-10-08): which area, which day, which arrival window, and whether a
 * dispatcher has approved it. Who they are lives on the lead it belongs to.
 *
 * Holds its window's seat in Openings while pending or approved and until the
 * lead has a job of its own, which then holds the seat instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('site_id');
            $table->uuid('lead_id');
            $table->uuid('service_area_id');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 20)->default('pending');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['service_area_id', 'date', 'status']);
            $table->unique('lead_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('web_sites')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('service_area_id')->references('id')->on('service_areas')->cascadeOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_bookings');
    }
};
