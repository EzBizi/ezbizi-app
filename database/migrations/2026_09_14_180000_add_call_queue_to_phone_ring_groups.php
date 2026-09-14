<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hold callers in a queue instead of sending them to voicemail.
 *
 * `overflow` is already a plain string, so the new 'queue' answer needs no
 * change of its own — what is new is what a queue has to be told: how long to
 * hold somebody before giving up, what to say while they wait, and what to
 * play between saying it.
 *
 * Defaults chosen so that switching a group to 'queue' and saving nothing else
 * behaves the way somebody would expect: five minutes of holding, position
 * announced, estimated wait not announced.
 *
 * Estimated wait is OFF by default on purpose. Twilio computes it from the
 * average wait of the callers currently in the queue, which on a queue with
 * two people in it is a number derived from two people — and a wrong estimate
 * is worse than none, because a caller who is told two minutes and waits six
 * hangs up angrier than one who was told nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_ring_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('queue_max_seconds')->default(300);
            $table->boolean('queue_announce_position')->default(true);
            $table->boolean('queue_announce_wait')->default(false);
            $table->string('queue_hold_music_url', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_ring_groups', function (Blueprint $table) {
            $table->dropColumn([
                'queue_max_seconds',
                'queue_announce_position',
                'queue_announce_wait',
                'queue_hold_music_url',
            ]);
        });
    }
};
