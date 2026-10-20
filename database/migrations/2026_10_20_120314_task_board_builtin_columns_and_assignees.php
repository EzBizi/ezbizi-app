<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

/**
 * Task Board (Jose, 2026-10-20): per-board settings for the built-in columns
 * (label, visible) and more than one owner per task. tasks.assignee_user_id
 * stays the primary owner, which Reports group on; every owner, the primary
 * included, has a task_assignees row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            $table->json('builtin_columns')->nullable()->after('visibility');
        });

        Schema::create('task_assignees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });

        $now = now();
        DB::table('tasks')->whereNotNull('assignee_user_id')->orderBy('id')
            ->chunk(500, function ($tasks) use ($now) {
                DB::table('task_assignees')->insertOrIgnore($tasks->map(fn ($t) => [
                    'id' => (string) Str::uuid(),
                    'organization_id' => $t->organization_id,
                    'task_id' => $t->id,
                    'user_id' => $t->assignee_user_id,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
        Schema::table('task_boards', function (Blueprint $table) {
            $table->dropColumn('builtin_columns');
        });
    }
};
