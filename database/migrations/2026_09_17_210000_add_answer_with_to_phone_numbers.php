<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a line does when a call arrives, said out loud.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * 2026-09-17: *"On Settings - Numbers on the Who it Rings, i want more options
 * besides ring groups, i should be able to choose IVR Menus, Ring Groups,
 * Voicemail, User Specific, Self."*
 *
 * ── Why a discriminator and not four more columns ────────────────
 *
 * Two of the five already have columns — `flow_id` for a menu and
 * `ring_group_id` for a group — and `inbound()` picks between them by ORDER:
 * a flow wins if one is set, otherwise the group, otherwise voicemail. That
 * order is invisible on a screen and it cannot express the other three at all.
 * "Voicemail" is indistinguishable from "nothing configured", which is why the
 * call-flow chart draws that line as a dead end; and there is nowhere to say
 * "ring this one person".
 *
 * So this adds the missing fact rather than a fourth way to store the same
 * ones: WHICH KIND of destination this line has chosen. The existing columns
 * stay the truth for the menu and the group, because a second copy of a flow
 * id is a second thing to keep in step.
 *
 * ── Why NULL is not a default value but a meaning ────────────────
 *
 * NULL means "as before": flow, then group, then voicemail. Every one of the
 * thirteen production lines keeps answering exactly as it does today with no
 * data migration and nothing to get wrong, and a line only gains the new
 * behaviour when somebody chooses it on the screen.
 *
 * It also keeps the company fallback working untouched. `PhoneNumber::resolved`
 * falls through to the connection's `default_<key>`, so a line with no
 * `answer_with` still inherits its company's flow or group the way it always
 * has. A company-wide `default_answer_with` is deliberately NOT added: nobody
 * has asked for one, and the inherited behaviour is already correct.
 *
 * ── Why the user id is its own column ───────────────────────────
 *
 * `answer_user_id` only means anything when `answer_with` is `user`, and a
 * nullable foreign key is the honest shape for that. The alternative — reusing
 * `ring_group_id` to hold a user id — is the kind of saving that costs a day
 * the first time somebody reads a group id out of it.
 *
 * `self` needs no column: the person a line belongs to is whoever holds a
 * `phone_extensions` row pointing at it, which is already how the Dial pad
 * decides which line is yours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('answer_with', 32)->nullable()->after('ring_group_id');
            $table->uuid('answer_user_id')->nullable()->after('answer_with');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['answer_with', 'answer_user_id']);
        });
    }
};
