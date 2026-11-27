<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where an imported row came from (A1 Tools, 2026-11-03), so re-running the import adds nothing. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('held_equipment', function (Blueprint $table) {
            $table->string('import_ref', 80)->nullable()->after('note');
            $table->unique(['organization_id', 'import_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('held_equipment', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'import_ref']);
            $table->dropColumn('import_ref');
        });
    }
};
