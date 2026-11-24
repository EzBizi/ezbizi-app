<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Report wrong ZIP" (Nelly Escobar, suggestion 01a20e5c, Jose-approved
 * 2026-11-24): a ZIP that resolved to the wrong area, waiting for an admin to
 * move it. Closed when moved or dismissed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zip_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('zip', 5);
            $table->foreignUuid('reported_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->foreignUuid('suggested_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignUuid('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('moved_to_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zip_reports');
    }
};
