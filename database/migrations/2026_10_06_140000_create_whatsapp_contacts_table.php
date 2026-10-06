<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has written to a WhatsApp number, and what they told us they want.
 *
 * One row per person per number: `kind` is unknown until they tap "I need a
 * service" (lead) or "I want to work with you" (hiring) - Jose, 2026-10-06.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number_id', 32);
            $table->string('counterparty', 32);
            $table->string('kind', 16)->default('unknown');
            $table->uuid('lead_id')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            $table->unique(['phone_number_id', 'counterparty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};
