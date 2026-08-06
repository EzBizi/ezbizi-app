<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logos for the organization and its companies (plan §12).
 *
 * §12: "theming is configuration, not code. That means colors, logo and
 * typography stored per organization, not compiled constants."
 *
 * ── Why franchises do not get one ────────────────────────────────
 *
 * A franchise is a GEOGRAPHY (§3), not a brand. "Connecticut" does not have a
 * logo; the company operating there does. Giving every franchise its own would
 * invite a business to put nine slightly different marks on nine sets of
 * paperwork, which is the opposite of what a brand is for.
 *
 * A path, not a blob. The file lives on the local disk beside avatars and is
 * served through a controller, so the column stays small and the bytes stay out
 * of every query that touches the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
        });

        Schema::table('companies', function (Blueprint $table) {
            // Nullable and inherited at render time rather than copied down:
            // a company with no logo of its own shows the organization's, and
            // keeps showing it when that one changes.
            $table->string('logo_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('logo_path'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('logo_path'));
    }
};
