<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the things a number points at an owner.
 *
 * ── The defect ──────────────────────────────────────────────────
 *
 * Numbers carry scope_type/scope_id. Ring groups, opening hours, IVR menus and
 * voicemail boxes did not — they hung off `connection_id` alone. A connection
 * is deliberately shared: ConnectionResolver walks franchise → company →
 * organization, so a business running one Twilio account at organization level
 * has EVERY company resolving to the same connection.
 *
 * So A-1 Chimney's ring group appeared under a sibling company. Nothing in the
 * data said whose it was. The missing thing was not a filter, it was a column.
 *
 * ── The backfill ────────────────────────────────────────────────
 *
 * Existing rows inherit their connection's scope, which is the only honest
 * answer available: a group made on a company's own Twilio account belongs to
 * that company, and one made on the organization's account belongs to the
 * organization. Rows whose connection is organization-scoped therefore stay
 * visible at organization view and disappear from company views — which is
 * the correction, not a loss.
 *
 * Nullable rather than defaulted, so "never scoped" and "scoped to the
 * organization" stay distinguishable. The reading code shows unscoped rows
 * everywhere rather than nowhere: a customer's existing ring groups vanishing
 * would read as data loss.
 */
return new class extends Migration
{
    private const TABLES = [
        'phone_ring_groups',
        'phone_hours_schedules',
        'phone_flows',
        'phone_voicemail_boxes',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'scope_type')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('scope_type', 16)->nullable()->after('connection_id');
                $blueprint->uuid('scope_id')->nullable()->after('scope_type');
                $blueprint->index(['scope_type', 'scope_id']);
            });

            // Inherit the connection's own scope. One statement per table
            // rather than a row loop: this runs on live data.
            DB::statement("
                UPDATE {$table} AS t
                JOIN phone_connections AS c ON c.id = t.connection_id
                SET t.scope_type = c.scope_type,
                    t.scope_id   = c.scope_id
                WHERE t.scope_type IS NULL
            ");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'scope_type')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['scope_type', 'scope_id']);
                $blueprint->dropColumn(['scope_type', 'scope_id']);
            });
        }
    }
};
