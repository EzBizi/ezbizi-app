<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "EzBizi AI · <rule>" on what an automation writes (AutomationVoice). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['alerts', 'task_updates', 'task_activities'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('sender_label', 120)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['alerts', 'task_updates', 'task_activities'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('sender_label'));
        }
    }
};
