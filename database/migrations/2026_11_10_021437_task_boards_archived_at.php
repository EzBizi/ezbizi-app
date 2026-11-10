<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A board archived by hand, reversibly (Jose, 2026-11-09: "I can't delete boards"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('edit_mode');
        });
    }

    public function down(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
