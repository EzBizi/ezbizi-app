<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Task Board (Jose, 2026-10-13): custom statuses and columns per board, and
 * one level of subtasks. Every existing board gets the four built-in statuses
 * and every existing task its status_id from the `status` key it already has.
 */
return new class extends Migration
{
    private const BUILT_IN = [
        ['not_started', 'Not started', '#C4C4C4', false],
        ['working_on_it', 'Working on it', '#FDAB3D', false],
        ['stuck', 'Stuck', '#E2445C', false],
        ['done', 'Done', '#00C875', true],
    ];

    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('key', 24)->nullable(); // built-ins only
            $table->string('name');
            $table->string('colour', 16)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_done')->default(false);
            $table->timestamps();
            $table->index(['board_id', 'position']);
        });

        Schema::create('task_columns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['text', 'number', 'status', 'date', 'person', 'dropdown', 'checkbox']);
            $table->json('settings')->nullable(); // {unit?, options?: [{id, label, colour}]}
            $table->boolean('hidden')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('task_column_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('column_id')->constrained('task_columns')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 15, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->foreignUuid('value_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'column_id']);
            $table->index(['column_id', 'value_number']);
            $table->index(['column_id', 'value_date']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('status_id')->nullable()->after('status')->constrained('task_statuses')->nullOnDelete();
            $table->foreignUuid('parent_task_id')->nullable()->after('board_id')->constrained('tasks')->cascadeOnDelete();
        });

        $now = now();
        foreach (DB::table('task_boards')->get(['id', 'organization_id']) as $board) {
            foreach (self::BUILT_IN as $position => [$key, $name, $colour, $done]) {
                $id = (string) Str::uuid();
                DB::table('task_statuses')->insert([
                    'id' => $id, 'organization_id' => $board->organization_id, 'board_id' => $board->id,
                    'key' => $key, 'name' => $name, 'colour' => $colour, 'position' => $position,
                    'is_done' => $done, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('tasks')->where('board_id', $board->id)->where('status', $key)->update(['status_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_task_id');
            $table->dropConstrainedForeignId('status_id');
        });
        Schema::dropIfExists('task_column_values');
        Schema::dropIfExists('task_columns');
        Schema::dropIfExists('task_statuses');
    }
};
