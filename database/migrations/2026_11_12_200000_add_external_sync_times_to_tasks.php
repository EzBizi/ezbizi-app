<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task kept in step with Monday (Jose, 2026-11-12: "is it possible to keep
 * them synced to each other ? at least till we get rid of monday"): Monday's
 * own updated_at when we last took its item, and when we last wrote the task
 * from it. Together they tell "Monday changed" from "somebody changed it here"
 * (docs/agents/monday-sync-design-2026-11-12.md, conflict rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('external_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['external_updated_at', 'external_synced_at']);
        });
    }
};
