<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Performance report's call transcripts split into who said it - IVR menu,
 * Dispatcher, Customer - by Grok, beside the raw Whisper words, which stay.
 * The same prompt and shape as Leads' (TranscriptSpeakers; Jose, 2026-10-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_report_transcripts', fn (Blueprint $table) => $table->mediumText('transcript_labelled')->nullable()->after('transcript'));
    }

    public function down(): void
    {
        Schema::table('call_report_transcripts', fn (Blueprint $table) => $table->dropColumn('transcript_labelled'));
    }
};
