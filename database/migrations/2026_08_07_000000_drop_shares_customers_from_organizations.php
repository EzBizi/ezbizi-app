<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer sharing is not a setting (plan §5.2).
 *
 * The column said it was one. It never behaved like one: ProvisionOrganization
 * wrote `true` into it and nothing anywhere — no policy, no query, no controller,
 * no screen — ever read it back. A flag that only one side of the system knows
 * about is worse than no flag, because the next person to find it assumes
 * turning it off does something.
 *
 * The owner's reasoning, verbatim (2026-08-07): "if i add a client to a
 * franchise, the client belongs to the franchise, but can be seen via
 * organization view, so technically it belongs to the organization itself."
 *
 * That is the hierarchy in §3 rather than a preference. A customer is entered
 * against a franchise; the franchise sits inside a company; the company sits
 * inside the organization. Org-wide visibility is what "the organization
 * contains that franchise" already means, so there is nothing to switch off —
 * the owning-franchise tag (§5.2) is what keeps accountability clear, not a
 * boolean.
 *
 * Safe to drop: one organization exists and its value is the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('shares_customers');
        });
    }

    public function down(): void
    {
        // Restores the column and its old default so a rollback lands on the
        // schema that was there before. It restores nothing that read it,
        // because nothing ever did.
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('shares_customers')->default(true)->after('franchise_label');
        });
    }
};
