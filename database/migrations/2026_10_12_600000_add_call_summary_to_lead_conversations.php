<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grok's read of a call: one-line summary, next step, and the signals the
 * follow-up queue and complaint alert use (Jose, 2026-10-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->text('call_summary')->nullable()->after('transcript_labelled');
            $table->text('call_next_step')->nullable()->after('call_summary');
            $table->json('call_signals')->nullable()->after('call_next_step');
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', fn (Blueprint $table) => $table->dropColumn(['call_summary', 'call_next_step', 'call_signals']));
    }
};
