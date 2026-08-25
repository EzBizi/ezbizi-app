<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port-in requests started from Settings (Task 13). Status webhooks update the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_port_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->json('e164s');
            $table->string('twilio_sid', 64);
            $table->string('status', 64);
            $table->text('status_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'twilio_sid']);
            $table->index(['organization_id', 'connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_port_requests');
    }
};
