<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign that turns itself on and off at set times (Jose, 2026-11-05:
 * "can we build a switch so it can turn it on/off at designated times?").
 * One row per campaign (or Meta ad set / ad); the scheduler applies it at each
 * edge through the same path the row's switch uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('social_connections')->cascadeOnDelete();
            $table->string('object_id', 32);
            $table->string('object_name')->nullable();
            $table->json('days');                       // ISO weekdays, 1 = Monday
            $table->string('on_at', 5);                 // HH:MM, in `timezone`
            $table->string('off_at', 5);                // earlier than on_at = overnight
            $table->string('timezone', 64);
            $table->string('last_state', 3)->nullable(); // on / off, the last edge applied
            $table->timestamp('last_applied_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'object_id'], 'ad_schedules_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_schedules');
    }
};
