<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camera configs — generic-protocol v1
 * (docs/superpowers/specs/2026-09-01-monitoring-cameras-design.md).
 *
 * Its own file, following the same rule as every other monitoring migration
 * (see 2026_08_31_140000_create_monitoring_screenshots_table.php): once a
 * migration has run anywhere it is spent, so a new table gets a new file
 * with a later timestamp rather than a Schema::create appended to one that
 * already ran.
 *
 * No credentials column here.
 * // ponytail: v1 assumes an open or tokenized/LAN stream URL — a company
 * pastes it once and the browser (or, for rtsp, an NVR deep-link) renders
 * it directly. Basic-auth-in-a-browser plus a server-side media proxy is a
 * real need for some cameras, but building it before a single customer has
 * asked is exactly the speculative column ponytail exists to cut. v2 adds
 * the credentials column and the proxy together, when it is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_cameras', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('label', 255);
            $table->string('protocol', 16); // hls|mjpeg|snapshot|rtsp
            $table->string('url', 2048);
            $table->boolean('enabled')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_cameras');
    }
};
