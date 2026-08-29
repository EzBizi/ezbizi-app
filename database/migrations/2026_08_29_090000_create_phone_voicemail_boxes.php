<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named voicemail boxes: a greeting, and who it belongs to.
 *
 * Until now every line took a message with the same sentence — "Please leave a
 * message after the tone." — with no way to say who the caller has reached or
 * when anybody will ring back. A business's voicemail greeting is the last
 * thing a customer hears before deciding whether to try a competitor, and it
 * was the one part of the call EzBizi gave nobody any say over.
 *
 * A box belongs to the whole company, or to a person, a ring group, or a
 * single number. `owner_id` is null for the company-wide box, which is why it
 * is nullable rather than polymorphic-with-a-sentinel: "the company" is not a
 * row, and inventing one to point at would be a lie in the schema.
 *
 * Additive: nothing references this yet except the numbers that choose to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_voicemail_boxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();

            $table->string('name', 191);

            // company | user | ring_group | number
            $table->string('owner_type', 24)->default('company');
            $table->uuid('owner_id')->nullable();

            // tts | audio. Audio is a recorded or uploaded file; TTS is spoken
            // by Twilio from `greeting_text`.
            $table->string('greeting_mode', 8)->default('tts');
            $table->text('greeting_text')->nullable();
            $table->string('greeting_voice', 48)->nullable();
            $table->string('greeting_language', 16)->default('en-US');
            $table->string('greeting_audio_url', 2048)->nullable();

            // Twilio's own bounds. Below ten seconds a caller cannot finish a
            // sentence; above an hour is not a voicemail.
            $table->unsignedSmallInteger('max_seconds')->default(300);

            $table->boolean('transcribe')->default(false);

            // Who is told a message was left. Emails rather than user ids: the
            // person who should hear about the sales voicemail is often not a
            // user of this system at all.
            $table->json('notify_emails')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'connection_id']);
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->uuid('voicemail_box_id')->nullable()->after('flow_id');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn('voicemail_box_id');
        });

        Schema::dropIfExists('phone_voicemail_boxes');
    }
};
