<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who took the call and when, kept with its words (Grok AI, 2026-10-13: per
 * person reviews for the Scoring tab need call -> person without asking
 * RingCentral again). Null on rows written before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_report_transcripts', function (Blueprint $table) {
            $table->string('answered_by', 191)->nullable()->after('source');
            $table->timestamp('call_started_at')->nullable()->after('answered_by');
            $table->index(['organization_id', 'answered_by', 'call_started_at'], 'call_report_transcripts_by_person');
        });
    }

    public function down(): void
    {
        Schema::table('call_report_transcripts', function (Blueprint $table) {
            $table->dropIndex('call_report_transcripts_by_person');
            $table->dropColumn(['answered_by', 'call_started_at']);
        });
    }
};
