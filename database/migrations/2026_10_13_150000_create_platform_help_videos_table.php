<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The how-to video beside each tab's title (UI queue 81, Jose 2026-10-13).
 *
 * Platform-wide and staff-owned, like the minimum client versions: the same
 * video for every organization, and a new one needs a row, not a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_help_videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // A module key from ModuleRegistry: the tab the video explains.
            $table->string('module_key', 64)->unique();
            $table->string('url', 2048);
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            // [{at: seconds, label}], drawn under the player so a part can be
            // jumped to.
            $table->json('chapters')->nullable();
            // WebVTT, so the words can be read with the sound off.
            $table->string('captions_url', 2048)->nullable();
            // A PlatformStaff label; the audit log carries the history.
            $table->string('changed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_help_videos');
    }
};
