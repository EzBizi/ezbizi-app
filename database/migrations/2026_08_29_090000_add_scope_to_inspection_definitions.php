<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a named inspection exists (engine design §4).
 *
 * The same `scope_type`/`scope_id` pair Automation, Mailbox and PhoneConnection
 * carry. NULL is the organization, and it is the default so that every
 * definition written before this migration keeps behaving exactly as it did — a
 * backfill to some chosen company would be this migration deciding something
 * nobody asked it to decide.
 *
 * No foreign key on `scope_id`, matching `automations`: the column names a row
 * in one of TWO tables depending on `scope_type`, and there is no constraint
 * that expresses that. InspectionDefinitionsController checks the id belongs to
 * this organization, and InspectionBuilderScopeTest pins that it refuses one
 * that does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_definitions', function (Blueprint $table) {
            $table->string('scope_type', 20)->nullable()->after('organization_id');
            $table->char('scope_id', 36)->nullable()->after('scope_type');

            // New Inspection asks "what exists for this place" on every load, so
            // the pair is worth an index even while the table is small.
            $table->index(
                ['organization_id', 'scope_type', 'scope_id'],
                'inspection_definitions_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inspection_definitions', function (Blueprint $table) {
            $table->dropIndex('inspection_definitions_scope_idx');
            $table->dropColumn(['scope_type', 'scope_id']);
        });
    }
};
