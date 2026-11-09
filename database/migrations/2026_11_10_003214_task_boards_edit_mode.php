<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who edits a board's tasks: everyone who can edit it, or only each task's owners (monday's assignee mode, Jose 2026-11-09). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            $table->string('edit_mode', 16)->default('everyone')->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            $table->dropColumn('edit_mode');
        });
    }
};
