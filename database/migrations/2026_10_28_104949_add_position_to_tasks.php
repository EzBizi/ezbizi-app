<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks keep the order people drag them into (Jose, 2026-10-28). Existing
 * tasks start at 0, so they keep reading in created order until moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('group_id');
            $table->index(['group_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
