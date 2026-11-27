<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle telematics and dash cams (Jose, 2026-11-16; docs/telematics-and-dashcams.md).
 * Provider-agnostic: a connection per vendor account, a device per box in a
 * van, positions and camera events in one shape whatever sent them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telematics_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('label');
            $table->text('credentials')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('status_detail')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('telematics_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('telematics_connections')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('label')->nullable();
            $table->string('kind', 20)->default('tracker'); // tracker | dashcam | both
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            // Latest reading, so the map needs no scan of the history.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('speed_mph')->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->boolean('ignition_on')->nullable();
            $table->unsignedInteger('odometer_mi')->nullable();
            $table->unsignedTinyInteger('fuel_pct')->nullable();
            $table->json('fault_codes')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'external_id']);
        });

        Schema::create('vehicle_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('telematics_devices')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            // Who was driving, from the vehicle's assignment at the time: the reason this is personal data.
            $table->foreignUuid('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('speed_mph')->nullable();
            $table->boolean('ignition_on')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['device_id', 'recorded_at']);
        });

        Schema::create('camera_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('telematics_devices')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignUuid('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_id');
            $table->string('type', 30);
            $table->string('severity', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('occurred_at');
            // The clip stays with the vendor: an id to re-sign, never a copy (same rule as calls).
            $table->string('vendor_media_id')->nullable();
            $table->text('url')->nullable();
            $table->timestamp('url_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'external_id']);
            $table->index(['organization_id', 'occurred_at']);
        });

        Schema::table('monitoring_cameras', function (Blueprint $table) {
            $table->foreignUuid('telematics_device_id')->nullable()->constrained('telematics_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_cameras', fn (Blueprint $t) => $t->dropConstrainedForeignId('telematics_device_id'));
        Schema::dropIfExists('camera_events');
        Schema::dropIfExists('vehicle_positions');
        Schema::dropIfExists('telematics_devices');
        Schema::dropIfExists('telematics_connections');
    }
};
