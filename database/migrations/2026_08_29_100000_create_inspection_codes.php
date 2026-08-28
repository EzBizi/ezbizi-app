<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The building codes an inspection cites (engine design §5.5).
 *
 * A1's tool holds these in a TypeScript file and cites `R1003.11` from five
 * different fields. Free text on each field would be five copies of the same
 * paragraph, and correcting one of them would leave four saying something else.
 *
 * ── Per organization, not platform-wide ─────────────────────────
 *
 * NFPA 211 does not vary by company, so a shared catalogue is tempting. It is
 * wrong here for the reason §2.1 gives: this is the software's opinion about a
 * customer's trade. A chimney company in Florida and a boiler company in
 * Manchester cite different documents, and an organization that wants to quote
 * its own state amendment must be able to. The seed can offer a starting set;
 * the rows belong to the tenant.
 *
 * ── `code` is not unique, deliberately ──────────────────────────
 *
 * Two organizations cite `R1003.11`, and one organization may keep two entries
 * for the same number — the 2018 wording and the 2021 wording — while it works
 * out which of its forms should say which. A unique index would refuse the
 * second before anybody discovered they needed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_codes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('organization_id', 36);

            // The number as it is cited: "R1003.11", "NFPA 211", "1926.706(b)".
            $table->string('code', 60);

            // The heading a technician reads: "R1003.11 FLUE LINING (MATERIAL)".
            $table->string('title', 255);

            // The requirement itself, in full. Printed on the report as the
            // violation, so it is text rather than a summary of text.
            $table->text('body');

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')
                ->cascadeOnDelete();

            // The library screen lists by code within an organization.
            $table->index(['organization_id', 'code'], 'inspection_codes_org_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_codes');
    }
};
