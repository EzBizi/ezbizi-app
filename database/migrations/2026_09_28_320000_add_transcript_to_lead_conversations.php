<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call's words. Kept apart from `text`, which is a message's own words and
 * what a call's list preview would otherwise become: the transcript is read
 * off the recording by a machine, and the thread says so.
 *
 * `transcript_operation` is Google's job name while it transcribes;
 * `transcribed_at` is set when it finished or was given up on, so a call is
 * never sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->text('transcript')->nullable()->after('text');
            $table->string('transcript_operation', 191)->nullable()->after('transcript');
            $table->timestamp('transcribed_at')->nullable()->after('transcript_operation');
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->dropColumn(['transcript', 'transcript_operation', 'transcribed_at']);
        });
    }
};
