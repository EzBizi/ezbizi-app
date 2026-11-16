<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monday's workspace folders and colours on the Task Board list (Jose 11-13):
 * user-made folders (nesting via parent_id), a colour per folder and per
 * board, and an org-wide order. Shared for the organization; whether a
 * folder is open stays on each person's device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_board_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('task_board_folders')->cascadeOnDelete();
            $table->string('name');
            $table->string('colour', 16)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'parent_id', 'position']);
        });

        Schema::table('task_boards', function (Blueprint $table) {
            $table->foreignUuid('folder_id')->nullable()->constrained('task_board_folders')->nullOnDelete();
            $table->string('colour', 16)->nullable();
            $table->unsignedInteger('position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn(['colour', 'position']);
        });
        Schema::dropIfExists('task_board_folders');
    }
};
