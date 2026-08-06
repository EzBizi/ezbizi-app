<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User preferences — §2.1 category 6.
 *
 * The six categories exist so they are never merged, and this is the one with no
 * home yet. Tenant configuration (category 5) has the settings registry and the
 * settings table; release flags have the control plane; entitlements will have
 * the subscription record. Preferences belong to the PERSON: nobody else sets
 * them, nobody else may read them, and an administrator turning on dark mode for
 * the whole organization would be absurd.
 *
 * ── Why a JSON column and not a table ────────────────────────────
 *
 * Settings need their own rows because they resolve through a scope chain —
 * franchise, company, organization, default — and each level's override has to
 * be findable and removable on its own. Preferences have exactly one scope: this
 * user. There is no inheritance, no cascade and no query that asks "who set
 * dark mode", so a row per key would be a join for nothing.
 *
 * The KEYS are still defined in code (PreferenceRegistry), for the same reason
 * setting definitions are: if the schema is editable data, nothing validates
 * anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
