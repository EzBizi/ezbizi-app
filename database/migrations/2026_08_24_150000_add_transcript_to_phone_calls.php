<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final transcription text on the call row (Task 11). Live utterance events
 * are not stored one-by-one; controllers concatenate finals here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->text('transcript')->nullable()->after('transcription_sid');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn('transcript');
        });
    }
};
