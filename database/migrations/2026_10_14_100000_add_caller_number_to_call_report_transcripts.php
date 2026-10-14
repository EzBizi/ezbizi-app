<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The caller's number and the recording, kept with the words (Jose,
 * 2026-10-14: a person's latest calls on the Scoring screen, searchable, each
 * playable). Null on rows written before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_report_transcripts', function (Blueprint $table) {
            $table->string('caller_number', 32)->nullable()->after('call_started_at');
            $table->string('recording_id', 64)->nullable()->after('caller_number');
        });
    }

    public function down(): void
    {
        Schema::table('call_report_transcripts', fn (Blueprint $table) => $table->dropColumn(['caller_number', 'recording_id']));
    }
};
