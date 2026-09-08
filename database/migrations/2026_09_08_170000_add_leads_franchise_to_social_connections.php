<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a connection's LEADS land, which is not where the connection is SEEN.
 *
 * `scope_type`/`scope_id` already answer "who can see this connection".
 * Assignment is the other question — which franchise owns the enquiries it
 * produces — and the two are deliberately separate: re-scoping the connection
 * itself would hide it from the company that assigned it, because
 * SocialConnectionAccess::inView makes a franchise-scoped connection invisible
 * to the company above.
 *
 * Null means company level, which is exactly today's behaviour, so there is
 * nothing to backfill.
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
