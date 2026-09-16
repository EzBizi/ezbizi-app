<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reply that software wrote, marked as such.
 *
 * ── The list it was quietly emptying ────────────────────────────
 *
 * "Unreplied" in Leads means the newest row is from the CONSUMER. Any
 * ADVERTISER row takes the enquiry out of that list the instant it lands — and
 * that is the list a business uses to find the people nobody has answered.
 *
 * So a website's automatic greeting, sent at t=0, made a brand new enquiry read
 * as answered. Web Management found it the expensive way: they defaulted their
 * auto-reply ON and three Leads tests went red. It ships defaulted OFF because
 * of it, and `WidgetDefaults` says so in a comment — a feature turned down to
 * avoid damaging somebody else's filter.
 *
 * Automations hit the same wall from the other side: Jose asked for a "reply to
 * the lead" rule, and every rule anybody wrote would have switched off their own
 * unreplied list as a side effect nobody asked for.
 *
 * ── Why a column and not a convention ───────────────────────────
 *
 * There is no existing signal. `lead_conversations` records the channel, the
 * participant and the words, and nothing about who or what produced the row —
 * so "was a person involved" cannot be derived from what is already stored, and
 * a guess from the text would be a guess.
 *
 * Defaulted false on purpose: every row written before today was either a human
 * reply or something the enquirer said, and both are correctly "not automatic".
 * No backfill is needed, which is the whole reason the default is that way
 * round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->boolean('is_automatic')->default(false)->after('participant');
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->dropColumn('is_automatic');
        });
    }
};
