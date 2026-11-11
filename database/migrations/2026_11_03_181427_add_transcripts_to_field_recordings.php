<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Field recordings become transcripts (Jose, 2026-11-03: "that gets
 * transcibed, we do need a way to filter out the silent bits"). Each chunk
 * is transcribed with VAD and its audio deleted; only the words, with clock
 * time, and a summary remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_recording_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recording_id');
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3);
            $table->text('text');
            $table->timestamps();

            $table->foreign('recording_id')->references('id')->on('field_recordings')->cascadeOnDelete();
            $table->index(['recording_id', 'started_at']);
        });

        Schema::table('field_recording_chunks', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
            $table->dateTime('transcribed_at')->nullable();
        });

        Schema::table('field_recordings', function (Blueprint $table) {
            $table->json('summary')->nullable();
            $table->dateTime('summarized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('field_recordings', fn (Blueprint $t) => $t->dropColumn(['summary', 'summarized_at']));
        Schema::table('field_recording_chunks', fn (Blueprint $t) => $t->dropColumn('transcribed_at'));
        Schema::dropIfExists('field_recording_segments');
    }
};
