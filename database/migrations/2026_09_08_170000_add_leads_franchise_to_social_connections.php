<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a connection's LEADS land, which is not where the connection is SEEN.
 *
 * `scope_type`/`scope_id` already answer "who can see this connection".
 * Assignment is the other question — which franchise owns the enquiries it
 * produces.
 *
 * Null means company level, which is exactly today's behaviour, so there is
 * nothing to backfill.
 *
 * ── CORRECTION, 2026-09-14: the reason given here was false ──────
 *
 * This file used to justify the separate column by saying that re-scoping the
 * connection "would hide it from the company that assigned it, because
 * SocialConnectionAccess::inView makes a franchise-scoped connection invisible
 * to the company above". Measured, both halves of that are wrong, and were
 * wrong on the day it was written:
 *
 *   inView(), COMPANY view, SCOPE_FRANCHISE arm
 *       in_array($connection->scope_id, $view->franchiseIds(), true)
 *       -> a company DOES see its own franchises' connections.
 *       Added d3f17b70, 2026-08-21 — eighteen days before this migration.
 *
 *   canActOnFranchise(), SCOPE_COMPANY arm
 *       a company-level assignment reaches that company's franchises and its
 *       sub-companies' franchises -> re-scoping does not take authority away
 *       from the person who connected it either.
 *
 * So placement by re-scoping would NOT have hidden the connection and would NOT
 * have removed anybody's ability to disconnect it. The column is close to
 * redundant with scope, and the one real difference is narrower than the reason
 * claimed: a company-scoped connection is visible to EVERY franchise in that
 * company (inView, FRANCHISE view, SCOPE_COMPANY arm), so re-scoping it to one
 * franchise hides it from that franchise's siblings. Whether that is wanted is a
 * product question nobody has been asked.
 *
 * Left in place rather than reverted: it is deployed, the client has a picker
 * bound to it, and ripping out a shipped column to satisfy a comment is the
 * larger risk. But the next person deciding where a per-franchise connection
 * fact belongs should weigh scope FIRST and reach for this column only if the
 * sibling-visibility difference is the one they actually want — which is the
 * question the Reports session asked on 2026-09-14 and the reason this was
 * measured at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->foreignUuid('leads_franchise_id')
                ->nullable()
                ->after('scope_id')
                // nullOnDelete, not cascade: a deleted franchise must not take
                // the connection with it. The account is still connected and
                // still producing leads; it simply falls back to company level.
                ->constrained('franchises')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leads_franchise_id');
        });
    }
};
