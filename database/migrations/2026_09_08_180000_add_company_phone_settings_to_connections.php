<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that belong to the COMPANY, on the row that already is one.
 *
 * ── Why here and not a new table ─────────────────────────────────
 *
 * `phone_connections` is unique on (organization, scope_type, scope_id), and
 * in practice every connection is a company's. So there is already exactly one
 * row per company holding that company's phone system, and a second table
 * keyed the same way would be a join to reach a fact that could have sat here.
 *
 * ── What this one is for ─────────────────────────────────────────
 *
 * Whether the person who took a call MUST say what it was about before the
 * desk is theirs again.
 *
 * Off by default, deliberately. A company that has not set up tags would
 * otherwise find its dial pad locked by the first call it took, by a
 * requirement nobody chose — a setting that arrives switched on is a setting
 * that breaks the thing it was meant to improve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phone_connections')) {
            return;
        }

        Schema::table('phone_connections', function (Blueprint $table) {
            $table->boolean('require_call_tag')->default(false)->after('status_reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('phone_connections')) {
            return;
        }

        Schema::table('phone_connections', function (Blueprint $table) {
            $table->dropColumn('require_call_tag');
        });
    }
};
