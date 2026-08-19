<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The working copy of a named inspection lives on the pointer row.
 *
 * Versions are written once at publish. Builder autosave has to land somewhere
 * that is still editable, so screens and the layout preset sit here until the
 * next publish copies them onto a new version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_definitions', function (Blueprint $table) {
            $table->string('layout_preset', 32)->default('step_strip')->after('name');
        });

        // JSON_ARRAY() so a raw insert (the table tests) still has a working copy
        // rather than 1364. Eloquent also stamps [] on create.
        DB::statement(
            'ALTER TABLE inspection_definitions ADD screens JSON NOT NULL DEFAULT (JSON_ARRAY()) AFTER layout_preset'
        );
    }

    public function down(): void
    {
        Schema::table('inspection_definitions', function (Blueprint $table) {
            $table->dropColumn(['layout_preset', 'screens']);
        });
    }
};
