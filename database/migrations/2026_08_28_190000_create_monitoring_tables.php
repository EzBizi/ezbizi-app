<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // A stable id the client persists locally; identifies a device across sessions.
            $table->string('device_key', 64);
            $table->string('label', 120)->nullable();
            $table->string('platform', 16); // web|android|ios|windows|macos|linux
            $table->json('capabilities');   // ['activity'] | ['activity','specs',...]
            $table->string('user_agent', 400)->nullable();
            $table->string('ip_address', 45)->nullable(); // IPv6 + v4 tail = 45
            $table->timestamp('last_seen_at')->nullable();
            $table->string('current_module', 64)->nullable();
            $table->json('specs')->nullable(); // agent devices only
            // Set when a device_offline event has been raised; cleared on the next
            // beat, so the event fires once per offline episode, not every scan.
            $table->timestamp('offline_alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'device_key']);
            $table->index(['organization_id', 'last_seen_at']);
        });

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
        Schema::dropIfExists('monitored_devices');
    }
};
