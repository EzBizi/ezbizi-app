<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call's transcript split into who said it - IVR menu, Dispatcher, Customer -
 * by Grok, beside the raw Whisper words, which stay (Jose, 2026-10-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', fn (Blueprint $table) => $table->mediumText('transcript_labelled')->nullable()->after('transcript'));
    }

    public function down(): void
    {
        Schema::table('lead_conversations', fn (Blueprint $table) => $table->dropColumn('transcript_labelled'));
    }
};
