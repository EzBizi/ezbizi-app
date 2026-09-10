<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The message somebody left, kept apart from the call it was left on.
 *
 * `transcript` holds live transcription: every final utterance of the whole
 * conversation, concatenated — which on a line with a menu means the greeting,
 * the recording notice and the IVR prompt, because Twilio transcribes what we
 * say as well as what the caller says.
 *
 * A voicemail is a different recording, made after everyone has hung up, and
 * Twilio transcribes it through a different callback. Both were being written
 * to one column, so the Voicemail screen showed callers their own call read
 * back to them. Jose asked why transcripts were turning up among the
 * voicemails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->text('voicemail_transcript')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn('voicemail_transcript');
        });
    }
};
