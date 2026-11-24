<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Jose, 2026-11-24: "STOP SHOWING REMOVED ACCOUNT, THIS GOES FOR EVERYWHERE IN THE APP". */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users' => 'name', 'audit_events' => 'actor_label', 'automation_events' => 'actor_label'] as $table => $column) {
            DB::table($table)->where($column, 'Removed account')->update([$column => 'Former staff']);
        }
    }

    public function down(): void {}
};
