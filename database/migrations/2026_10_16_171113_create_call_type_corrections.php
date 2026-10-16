<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New or existing client, per inbound call, from evidence rather than the key
 * the caller pressed (Jose, 2026-10-16: "perhaps they are a new client and
 * press 2 ... so from the transcript as well as the existance in database we
 * should be able to automatically correct all of that"). The pressed queue is
 * kept beside the correction and never overwritten. Leads tags from it;
 * Reports (Performance, Scoring) counts from it (CallTypeCorrections::forCalls).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_type_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('call_id', 64);               // RingCentral's call-log id; 'ez:<id>' for an EzBizi call
            $table->string('pressed_queue', 120)->nullable();
            $table->string('corrected_type', 10);        // new | existing
            $table->string('reason', 255);
            $table->string('evidence', 20);              // customer_match | job_match | transcript | person
            $table->unsignedTinyInteger('confidence');   // 0-100
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['organization_id', 'call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_type_corrections');
    }
};
