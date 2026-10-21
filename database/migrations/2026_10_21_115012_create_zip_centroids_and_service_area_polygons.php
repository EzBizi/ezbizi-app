<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drawn service areas (Jose, 2026-10-21: "add them in bulk the way workiz
 * does, by selecting and drawing over the area that is covered").
 *
 * `zip_centroids` is public reference data, one row per US ZCTA from the
 * Census Gazetteer, filled by `scheduling:load-zip-centroids`. It belongs to no
 * tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zip_centroids', function (Blueprint $table) {
            $table->string('zip', 5)->primary();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('service_area_polygons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('service_area_id')->constrained()->cascadeOnDelete();
            // [[lat, lng], ...], at least three points, not closed.
            $table->json('points');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_area_polygons');
        Schema::dropIfExists('zip_centroids');
    }
};
