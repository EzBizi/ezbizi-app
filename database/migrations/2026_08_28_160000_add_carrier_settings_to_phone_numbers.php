<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-line Twilio settings EzBizi held opinions about but never stored.
 *
 * `emergency_address_set` has existed since the module was created and nothing
 * ever wrote it, because there was no way to choose an address. It stays as
 * the answer to "is this line safe to call 911 from"; the SID beside it is
 * which address Twilio actually holds, so the screen can name it rather than
 * just claim one exists.
 *
 * `capabilities` is a snapshot, not a source of truth — Twilio owns it. It is
 * stored because the alternative is asking Twilio on every render of the
 * Numbers list, and a list that cannot draw when Twilio is slow is worse than
 * a capability column that is a few minutes stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('emergency_address_sid', 34)->nullable()->after('emergency_address_set');

            // Twilio's inbound CNAM dip: costs per lookup, so it is a choice
            // rather than something we turn on for everybody.
            $table->boolean('voice_caller_id_lookup')->default(false)->after('emergency_address_sid');

            // A separate Twilio flag from `transcribe`, which is the live call.
            $table->boolean('voicemail_transcribe')->default(false)->after('voice_caller_id_lookup');

            $table->json('capabilities')->nullable()->after('voicemail_transcribe');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn([
                'emergency_address_sid',
                'voice_caller_id_lookup',
                'voicemail_transcribe',
                'capabilities',
            ]);
        });
    }
};
