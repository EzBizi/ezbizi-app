<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an imported board, task or update came from ("monday:<id>"), so a
 * re-run of the import skips what it already brought over (Jose, 2026-11-03:
 * replace Monday by Nov 10), and the Timeline and Formula column types.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['task_boards', 'tasks', 'task_updates'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('external_ref', 80)->nullable();
                $table->unique(['organization_id', 'external_ref']);
            });
        }
        // Design 178: Timeline and Formula columns.
        Schema::table('task_columns', function (Blueprint $table) {
            $table->enum('type', ['text', 'number', 'status', 'date', 'person', 'dropdown', 'checkbox', 'timeline', 'formula'])->change();
        });
    }

    public function down(): void
    {
        foreach (['task_boards', 'tasks', 'task_updates'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'external_ref']);
                $table->dropColumn('external_ref');
            });
        }
    }
};
