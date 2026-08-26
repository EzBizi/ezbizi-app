<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collaboration rows repeat organization, board, task, update, and user identity
 * so cross-place relationships are rejected by MySQL below the service layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_collaborators', function (Blueprint $table) {
            $this->identity($table);
            $table->uuid('user_id');
            $table->uuid('added_by');
            $table->timestamps();

            $table->unique(['task_id', 'user_id'], 'task_collaborators_task_user_unique');
            $this->taskForeign($table, 'task_collaborators_task_foreign');
            $this->userForeign($table, 'user_id', 'task_collaborators_user_foreign');
            $this->userForeign($table, 'added_by', 'task_collaborators_added_by_foreign');
        });

        Schema::create('task_watch_preferences', function (Blueprint $table) {
            $this->identity($table);
            $table->uuid('user_id');
            $table->enum('preference', ['watched', 'muted']);
            $table->timestamps();

            $table->unique(['task_id', 'user_id'], 'task_watch_preferences_task_user_unique');
            $this->taskForeign($table, 'task_watch_preferences_task_foreign');
            $this->userForeign($table, 'user_id', 'task_watch_preferences_user_foreign');
        });

        Schema::create('task_updates', function (Blueprint $table) {
            $this->identity($table);
            $table->uuid('author_user_id');
            $table->longText('body');
            $table->text('body_plain');
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key', 100);
            $table->timestamp('archived_at')->nullable();
            $table->uuid('archived_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'board_id', 'task_id', 'id'], 'task_updates_task_id_unique');
            $table->unique(['task_id', 'idempotency_key'], 'task_updates_idempotency_unique');
            $this->taskForeign($table, 'task_updates_task_foreign');
            $this->userForeign($table, 'author_user_id', 'task_updates_author_foreign');
            $this->userForeign($table, 'archived_by', 'task_updates_archived_by_foreign');
        });

        Schema::create('task_update_versions', function (Blueprint $table) {
            $this->identity($table);
            $table->uuid('update_id');
            $table->unsignedInteger('version');
            $table->longText('body');
            $table->text('body_plain');
            $table->uuid('edited_by');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['update_id', 'version'], 'task_update_versions_update_version_unique');
            $this->taskForeign($table, 'task_update_versions_task_foreign');
            $this->updateForeign($table, 'task_update_versions_update_foreign');
            $this->userForeign($table, 'edited_by', 'task_update_versions_editor_foreign');
        });

        Schema::create('task_update_mentions', function (Blueprint $table) {
            $this->identity($table);
            $table->uuid('update_id');
            $table->uuid('user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['update_id', 'user_id'], 'task_update_mentions_update_user_unique');
            $this->taskForeign($table, 'task_update_mentions_task_foreign');
            $this->updateForeign($table, 'task_update_mentions_update_foreign');
            $this->userForeign($table, 'user_id', 'task_update_mentions_user_foreign');
        });

        Schema::create('task_update_attachment_candidates', function (Blueprint $table) {
            $this->identity($table);
            $table->uuid('attachment_id');
            $table->uuid('actor_user_id');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique('attachment_id', 'task_update_candidates_attachment_unique');
            $table->index(['expires_at', 'id'], 'task_update_candidates_expiry_index');
            $this->taskForeign($table, 'task_update_candidates_task_foreign');
            $table->foreign(['organization_id', 'attachment_id'], 'task_update_candidates_attachment_foreign')
                ->references(['organization_id', 'id'])->on('attachments')->restrictOnDelete();
            $this->userForeign($table, 'actor_user_id', 'task_update_candidates_actor_foreign');
        });

        Schema::create('task_activities', function (Blueprint $table) {
            $this->identity($table);
            $table->uuid('actor_user_id')->nullable();
            $table->string('kind', 80);
            $table->string('summary', 500);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'board_id', 'task_id', 'created_at'], 'task_activities_feed_index');
            $this->taskForeign($table, 'task_activities_task_foreign');
            $this->userForeign($table, 'actor_user_id', 'task_activities_actor_foreign');
        });

        Schema::create('task_record_links', function (Blueprint $table) {
            $this->identity($table);
            $table->string('record_type', 80);
            $table->uuid('record_id');
            $table->json('display_snapshot');
            $table->uuid('created_by');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['task_id', 'record_type', 'record_id'], 'task_record_links_record_unique');
            $table->index(['organization_id', 'record_type', 'record_id'], 'task_record_links_reverse_index');
            $this->taskForeign($table, 'task_record_links_task_foreign');
            $this->userForeign($table, 'created_by', 'task_record_links_creator_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_record_links');
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('task_update_attachment_candidates');
        Schema::dropIfExists('task_update_mentions');
        Schema::dropIfExists('task_update_versions');
        Schema::dropIfExists('task_updates');
        Schema::dropIfExists('task_watch_preferences');
        Schema::dropIfExists('task_collaborators');
    }

    private function identity(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
        $table->uuid('board_id');
        $table->uuid('task_id');
    }

    private function taskForeign(Blueprint $table, string $name): void
    {
        $table->foreign(['organization_id', 'board_id', 'task_id'], $name)
            ->references(['organization_id', 'board_id', 'id'])->on('tasks')->restrictOnDelete();
    }

    private function userForeign(Blueprint $table, string $column, string $name): void
    {
        $table->foreign(['organization_id', $column], $name)
            ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
    }

    private function updateForeign(Blueprint $table, string $name): void
    {
        $table->foreign(['organization_id', 'board_id', 'task_id', 'update_id'], $name)
            ->references(['organization_id', 'board_id', 'task_id', 'id'])->on('task_updates')->restrictOnDelete();
    }
};
