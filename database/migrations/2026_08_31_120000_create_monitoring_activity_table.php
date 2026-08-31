<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tab-dwell log — its own file, and why it has to be.
 *
 * `monitoring_activity` was first written into
 * 2026_08_28_190000_create_monitoring_tables.php beside `monitored_devices`.
 * But that file had already run on production (batch 57) with only the devices
 * table, and Laravel keys the migrations table by FILENAME: a name already
 * recorded is skipped in full, so a second Schema::create added to it would
 * never execute on any box that had run it — the code would query a table that
 * production never creates. A fresh test database builds from scratch and runs
 * the file, so every test passed and none could see it.
 *
 * The rule this file exists to obey: once a migration has run anywhere it will
 * run, its file is spent — a new table gets a new file with a later timestamp.
 * The FK to `monitored_devices` is safe because that table is created by the
 * earlier (already-run) file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_activity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('monitored_devices')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->unsignedInteger('dwell_seconds');
            // Append-only: timed by when it happened, not by row creation. The sweep
            // measures the 3-month window from this column.
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_activity');
    }
};
