<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic screen captures, one row per JPEG on disk.
 *
 * Its own file, following 2026_08_31_120000_create_monitoring_activity_table.php:
 * once a migration has run anywhere it is spent, so a new table gets a new file
 * with a later timestamp rather than a second Schema::create appended to one that
 * already ran. The FK to `monitored_devices` is safe because that table is
 * created by an earlier, already-run file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_screenshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('monitored_devices')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // The sweep measures the 1-month window from this column, not row creation.
            $table->timestamp('captured_at')->index();
            $table->string('path', 255);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_screenshots');
    }
};
