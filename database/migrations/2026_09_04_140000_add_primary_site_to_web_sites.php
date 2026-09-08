<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One site per company is the PRIMARY, and the others inherit from it.
 *
 * ── What this changes about a value ──
 *
 * Before this, every site answered only for itself: setting a company name on
 * three sites meant typing it three times and remembering all three when it
 * changed. That is the problem this module exists to solve for pages, one level
 * up — the pitch is "change it once", and it was making people change it once
 * PER SITE.
 *
 * A value now resolves in a chain: the page's override, then the site's own
 * value, then the primary site's. A branch that says nothing about its company
 * name publishes the primary's; one that sets its own overrides it, for that
 * key alone.
 *
 * ── Why ONE nullable column rather than a boolean plus a mirror ──
 *
 * "At most one primary per company" is an invariant, and one enforced only in a
 * controller is one a seeder or a second endpoint can break. The obvious trick —
 * a stored generated column that is the company id when primary and NULL
 * otherwise, with a unique index — does NOT work here: `company_id` carries an
 * ON DELETE CASCADE foreign key, and InnoDB refuses a stored generated column
 * derived from an FK-with-cascade column (reported, misleadingly, as error 1215
 * "cannot add foreign key constraint").
 *
 * So the column IS the fact rather than a mirror of one. `primary_company_id`
 * holds this row's company_id when it is primary and NULL when it is not; the
 * unique index makes a second primary a database error, because NULLs do not
 * collide. `WebSite::is_primary` is a read-only accessor over it, so nothing
 * has to keep two fields in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->char('primary_company_id', 36)->nullable()->unique()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropUnique(['primary_company_id']);
            $table->dropColumn('primary_company_id');
        });
    }
};
