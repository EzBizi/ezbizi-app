<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which mailbox a voicemail was left in, when it was not simply the line's.
 *
 * Jose, 2026-10-05: "voicemail settings for each queue, each user". A box
 * could already belong to a ring group or a person, and no call ever reached
 * one: voicemail always used the line's box. Now a group's or person's call
 * leaves its message in their box, and this says which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->uuid('voicemail_box_id')->nullable()->after('voicemail');
            $table->index('voicemail_box_id');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropIndex(['voicemail_box_id']);
            $table->dropColumn('voicemail_box_id');
        });
    }
};
