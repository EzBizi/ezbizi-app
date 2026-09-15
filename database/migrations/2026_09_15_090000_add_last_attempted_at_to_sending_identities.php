<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Has anything ever been sent through this server?
 *
 * ── Why a column rather than a sentence on a screen ──────────────
 *
 * The sending-server screen has a state UI/UX drew as "Saved, never tried". It
 * is true of every row that exists today, because nothing sends yet — and it
 * goes false the moment the first send happens, in a module that is not this
 * one. Nothing here would have to change for that screen to start lying, and it
 * would lie in the calm voice of an honest empty state, which is worse than a
 * wrong error.
 *
 * UI/UX's own rule is what decides it: a state is available when the SERVER can
 * distinguish it, not when the screen would like it. Without this column the
 * server cannot tell "nobody has tried" from "somebody tried and it failed", so
 * the screen was not entitled to say either. With it, the sentence is derived
 * from a fact instead of asserted from an assumption, and it stops being true
 * on its own the first time a send writes here — no coordination, nothing for
 * anybody to remember.
 *
 * ── Deliberately NOT last_checked_at ─────────────────────────────
 *
 * That column is sitting right there and it is dead: the domain-verification
 * flow that wrote it was deleted on 2026-09-14, so nothing assigns to it. It
 * would have saved a migration. It means "when did we last look at this
 * tenant's DNS", and "when did we last try to send through their server" is a
 * different question whose answers happen to be the same shape. Two meanings on
 * one column agree until somebody restores one of the features, and then they
 * disagree silently.
 *
 * ── One column, not four ─────────────────────────────────────────
 *
 * The screen will eventually want refused / unreachable / not-ready, which the
 * Email module's socket can already tell apart by SMTP reply code. Those need
 * somewhere to live too, and this is deliberately NOT that: nothing can produce
 * them yet, and a column shaped for states nothing writes is a guess about what
 * they will need. This answers the one question that IS askable today — has
 * anything been tried — and the rest arrives with the sending path that can
 * populate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sending_identities', function (Blueprint $table) {
            /*
             * Null means NOBODY HAS TRIED, and that reading has to survive a
             * row written before this column existed. Every such row predates
             * any sending path at all — throughTenantProvider refuses for every
             * tenant today — so null is correct for them rather than merely
             * convenient, and there is no backfill to get wrong.
             */
            $table->timestamp('last_attempted_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('sending_identities', function (Blueprint $table) {
            $table->dropColumn('last_attempted_at');
        });
    }
};
