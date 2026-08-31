<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guides, tests, and who has done them
 * (docs/superpowers/specs/2026-08-28-training-module-design.md).
 *
 * Screens and questions are JSON rather than child tables: neither has an
 * identity anything queries, a permission of its own, or a life outside its
 * parent, and reordering is a list splice instead of a position-column
 * rewrite. "Which guides embed this video" is the query this gives up.
 *
 * The place pair (company_id, franchise_id) is the same one Inventory and
 * Services carry, which is what lets InventoryVisibility::constrain serve
 * this module without a new engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_guides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->string('title', 191);
            $table->string('summary', 500)->nullable();
            $table->string('status', 16);
            $table->json('screens');
            $table->timestamps();

            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('training_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->foreignUuid('guide_id')->nullable()
                ->constrained('training_guides')->nullOnDelete();
            $table->string('title', 191);
            $table->string('status', 16);
            $table->json('questions');
            $table->boolean('requires_guide')->default(false);
            $table->unsignedTinyInteger('pass_mark')->default(80);
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->unsignedSmallInteger('valid_for_days')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'company_id', 'franchise_id']);
        });

        Schema::create('training_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->foreignUuid('guide_id')->nullable()
                ->constrained('training_guides')->cascadeOnDelete();
            $table->foreignUuid('test_id')->nullable()
                ->constrained('training_tests')->cascadeOnDelete();
            $table->string('assignee_type', 8);          // user | role
            $table->uuid('assignee_id');
            $table->date('due_on')->nullable();
            $table->foreignUuid('assigned_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'assignee_type', 'assignee_id'], 'training_assignments_org_assignee_index');
        });

        Schema::create('training_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guide_id')->constrained('training_guides')->cascadeOnDelete();
            $table->unsignedSmallInteger('last_screen')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'guide_id']);
        });

        Schema::create('training_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('test_id')->constrained('training_tests')->cascadeOnDelete();
            $table->json('answers');
            $table->unsignedTinyInteger('score');
            $table->boolean('passed');
            $table->timestamp('taken_at');
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'test_id', 'user_id']);
            $table->index(['organization_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_attempts');
        Schema::dropIfExists('training_progress');
        Schema::dropIfExists('training_assignments');
        Schema::dropIfExists('training_tests');
        Schema::dropIfExists('training_guides');
    }

    private function placeColumns(Blueprint $table): void
    {
        $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
        $table->foreignUuid('franchise_id')->nullable()->constrained('franchises')->nullOnDelete();
    }
};
