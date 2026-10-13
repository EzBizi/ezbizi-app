<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Task Board (Jose, 2026-10-13, UI queue 80): one board for every company,
 * every franchise and every person. A board is owned by exactly one of the
 * three, which is why each kind gets its own unique index — MySQL treats NULLs
 * as distinct, so the two columns a kind does not use never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_boards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // user | franchise | company
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['kind', 'company_id']);
            $table->unique(['kind', 'franchise_id']);
            $table->unique(['kind', 'owner_user_id']);
        });

        Schema::create('task_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name');
            $table->string('colour', 16)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('task_groups')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 24)->default('not_started');
            $table->foreignUuid('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('about_type', 16)->nullable();
            $table->uuid('about_id')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('created_by_automation_id')->nullable();
            $table->string('priority', 16)->nullable();
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->timestamp('overdue_announced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['franchise_id', 'status', 'due_on']);
            $table->index(['assignee_user_id', 'completed_at']);
            $table->index(['board_id', 'group_id']);
        });

        Schema::create('task_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // Every existing company, franchise and person gets their board.
        // Idempotent; new rows get theirs from the model events.
        Artisan::call('ezbizi:backfill-task-boards');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_updates');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_groups');
        Schema::dropIfExists('task_boards');
    }
};
