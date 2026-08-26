<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who, at Meta, authorised this connection.
 *
 * ── Why a column and not a key inside `credentials` ──────────────
 *
 * Meta's deauthorize and data-deletion callbacks arrive unauthenticated, from
 * Meta, carrying one identifier: the app-scoped user id of the person who
 * removed the app. Answering them means finding every connection that person
 * authorised — a lookup, on every row, from a value we are handed.
 *
 * `credentials` is `encrypted:array`, so it cannot be queried at all: matching
 * on it would mean decrypting every social connection on the platform on every
 * callback. The id is not a secret — it is scoped to this app and useless
 * anywhere else — so it sits in the open where an index can reach it.
 *
 * ── Why it is nullable ───────────────────────────────────────────
 *
 * Only Meta issues one. Pinterest, TikTok, LinkedIn, YouTube, X and Bluesky
 * connections have no such id and never will, and a connection made before this
 * migration has none either — those simply do not match a callback, which is
 * the honest outcome rather than a wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->string('meta_user_id')->nullable()->after('remote_id');

            // Not unique: one Meta login can authorise several Pages, and each
            // Page is its own connection row. The callback is meant to find all
            // of them.
            $table->index(['provider', 'meta_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropIndex(['provider', 'meta_user_id']);
            $table->dropColumn('meta_user_id');
        });
    }
};
