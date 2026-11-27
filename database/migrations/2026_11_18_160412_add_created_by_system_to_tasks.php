<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Desk 11-18 (Jose: "anything created by any automation from ezbizi should have
// the ezbizi logo"): a task a system command made, with no person and no rule.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('created_by_system', 40)->nullable()->after('created_by_automation_id');
        });
        // The Workiz flag sweeps' tasks so far (address, Friday window, full window).
        DB::table('tasks')->whereNull('created_by_user_id')->whereNull('created_by_automation_id')
            ->where('title', 'like', 'Job #%')->update(['created_by_system' => 'flags']);
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('created_by_system'));
    }
};
