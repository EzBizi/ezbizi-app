<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shoval's 11-13 list for turning Monday off: reminders on a task, and a nudge when a task has
 * gone a week without an update (stale_announced_at makes that once per quiet spell).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('remind_at');
            $table->string('note', 255)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['sent_at', 'remind_at']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('stale_announced_at')->nullable()->after('overdue_announced_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('stale_announced_at'));
        Schema::dropIfExists('task_reminders');
    }
};
