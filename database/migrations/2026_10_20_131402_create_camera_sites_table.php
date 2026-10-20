<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A camera site: one physical place (a warehouse) whose cameras reach EzBizi
 * through a bridge — the EzBizi Windows app on a PC there that stays on
 * (docs/superpowers/specs/2026-10-20-camera-bridge-design.md).
 *
 * Filed under a company or franchise like other places, so its cameras follow
 * the company switcher. The bridge authenticates with a site key; only its
 * SHA-256 is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camera_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->foreignUuid('device_id')->nullable()->constrained('monitored_devices')->nullOnDelete();
            $table->char('site_key_hash', 64)->nullable()->unique();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_checkin_at')->nullable();
            // What the bridge last said about itself: engine version, what it found.
            $table->json('status')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_sites');
    }
};
