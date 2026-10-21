<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ZIP a person took out of every area. `scheduling:build-zip-map` adds any
 * unassigned ZIP its job evidence names, so without this it re-added 20662 to
 * Maryland twelve seconds after Jose removed it (2026-10-21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_area_zip_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('zip', 16);
            $table->foreignUuid('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'zip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_area_zip_releases');
    }
};
