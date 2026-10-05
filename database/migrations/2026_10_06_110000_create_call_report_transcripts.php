<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words of a RingCentral call on the Experimental report (Jose,
 * 2026-10-05: "Show Caller Number, Transcript"). Taken from the Local Services
 * lead call when the same call was already transcribed there, otherwise by
 * Whisper on EzBizi's own server. The audio is never kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_report_transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('call_id', 64); // RingCentral's call-log id
            $table->longText('transcript')->nullable(); // null when it could not be done
            $table->string('source', 16); // lead | whisper | failed
            $table->timestamps();

            $table->unique(['organization_id', 'call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_report_transcripts');
    }
};
