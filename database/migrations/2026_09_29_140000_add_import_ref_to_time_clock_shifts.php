<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an imported shift came from, e.g. `a1tools:4812` (2026-09-29).
 *
 * The A1 Tools staff migration (docs/A1-TOOLS-PEOPLE-MIGRATION.md) is run
 * dry, then for real, and possibly again at the cut-over to pick up shifts
 * that were still open the first time. Without a key of its own an importer
 * can only guess "already imported" from times, and a guess that is wrong
 * doubles somebody's hours. Null on every shift a person punched in EzBizi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->string('import_ref', 64)->nullable()->after('ended_by');
            $table->unique(['organization_id', 'import_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'import_ref']);
            $table->dropColumn('import_ref');
        });
    }
};
