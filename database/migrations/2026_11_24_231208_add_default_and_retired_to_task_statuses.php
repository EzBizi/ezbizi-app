<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monday's status label editor (gap review P1, 11-24): the label a new task
 * starts on, and labels retired from the pickers but kept on their tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_done');
            $table->boolean('retired')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'retired']);
        });
    }
};
