<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task rows repeat their organization and board identity so MySQL, not a later
 * writer, rejects a foreign group, parent, option, user, or typed-value target.
 * A subtask stores only its parent: the parent's group is its effective group.
 */
return new class extends Migration
{
    private const ATTACHMENT_CANDIDATE_KEY = 'tasks_attachments_organization_id_id_unique';
    private const OPTION_CANDIDATE_KEY = 'tasks_options_organization_board_definition_id_unique';
    private const OPTION_DEFINITION_INDEX = 'tasks_options_definition_lookup_index';

    public function up(): void
    {
        $this->addCandidateKey('attachments', ['organization_id', 'id'], self::ATTACHMENT_CANDIDATE_KEY);
        Schema::table('task_column_options', fn (Blueprint $table) => $table->index(
            ['organization_id', 'board_id', 'column_definition_id'],
            self::OPTION_DEFINITION_INDEX,
        ));
        $this->addCandidateKey('task_column_options', ['organization_id', 'board_id', 'column_definition_id', 'id'], self::OPTION_CANDIDATE_KEY);

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('group_id')->nullable();
            $table->uuid('parent_task_id')->nullable();
            $table->string('number', 40);
            $table->string('title', 191);
            $table->longText('description')->nullable();
            $table->text('description_plain')->nullable();
            $table->uuid('owner_user_id')->nullable();
            $table->uuid('status_option_id');
            $table->uuid('priority_option_id')->nullable();
            $table->date('start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedInteger('due_revision')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->uuid('created_by');
            $table->uuid('updated_by');
            $table->integer('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('detail_version')->default(1);
            $table->uuid('archived_with_parent_id')->nullable();
            $table->string('ordering_parent_key', 80)
                ->storedAs("IF(`parent_task_id` IS NULL, CONCAT('group:', `group_id`), CONCAT('task:', `parent_task_id`))");
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'tasks_organization_number_unique');
            $table->unique(['organization_id', 'board_id', 'id'], 'tasks_organization_board_id_unique');
            $table->unique(['organization_id', 'board_id', 'ordering_parent_key', 'active_position'], 'tasks_parent_active_position_unique');
            $table->index(['organization_id', 'board_id', 'group_id', 'position'], 'tasks_top_level_position_index');
            $table->index(['organization_id', 'board_id', 'parent_task_id', 'position'], 'tasks_subtask_position_index');
            $table->index(['organization_id', 'owner_user_id', 'due_on'], 'tasks_owner_due_index');
            $table->index(['organization_id', 'board_id', 'status_option_id', 'archived_at'], 'tasks_status_archive_index');

            $table->foreign(['organization_id', 'board_id'], 'tasks_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'group_id'], 'tasks_group_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_groups')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'status_option_id'], 'tasks_status_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_status_options')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'priority_option_id'], 'tasks_priority_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_priority_options')->restrictOnDelete();
            $this->userForeign($table, 'owner_user_id', 'tasks_owner_foreign');
            $this->userForeign($table, 'completed_by', 'tasks_completed_by_foreign');
            $this->userForeign($table, 'cancelled_by', 'tasks_cancelled_by_foreign');
            $this->userForeign($table, 'created_by', 'tasks_created_by_foreign');
            $this->userForeign($table, 'updated_by', 'tasks_updated_by_foreign');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign(['organization_id', 'board_id', 'parent_task_id'], 'tasks_parent_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'archived_with_parent_id'], 'tasks_archived_with_parent_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->restrictOnDelete();
        });

        Schema::create('task_column_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('task_id');
            $table->uuid('column_definition_id');
            $table->text('text_value')->nullable();
            $table->decimal('number_value', 20, 6)->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->date('date_value')->nullable();
            $table->string('url_value', 2048)->nullable();
            $table->string('link_label_value', 191)->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'column_definition_id'], 'task_column_values_task_definition_unique');
            $table->foreign(['organization_id', 'board_id', 'task_id'], 'task_column_values_task_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'column_definition_id'], 'task_column_values_definition_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_column_definitions')->restrictOnDelete();
        });

        Schema::create('task_column_people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('task_id');
            $table->uuid('column_definition_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->unique(['task_id', 'column_definition_id', 'user_id'], 'task_column_people_target_unique');
            $table->foreign(['organization_id', 'board_id', 'task_id'], 'task_column_people_task_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'column_definition_id'], 'task_column_people_definition_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_column_definitions')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'], 'task_column_people_user_foreign')
                ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('task_column_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('task_id');
            $table->uuid('column_definition_id');
            $table->uuid('column_option_id');
            $table->timestamps();

            $table->unique(['task_id', 'column_definition_id', 'column_option_id'], 'task_column_labels_target_unique');
            $table->foreign(['organization_id', 'board_id', 'task_id'], 'task_column_labels_task_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'column_definition_id'], 'task_column_labels_definition_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_column_definitions')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'column_definition_id', 'column_option_id'], 'task_column_labels_option_foreign')
                ->references(['organization_id', 'board_id', 'column_definition_id', 'id'])->on('task_column_options')->restrictOnDelete();
        });

        Schema::create('task_column_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('task_id');
            $table->uuid('column_definition_id');
            $table->uuid('attachment_id');
            $table->timestamps();

            $table->unique(['task_id', 'column_definition_id', 'attachment_id'], 'task_column_files_target_unique');
            $table->foreign(['organization_id', 'board_id', 'task_id'], 'task_column_files_task_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'column_definition_id'], 'task_column_files_definition_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_column_definitions')->restrictOnDelete();
            $table->foreign(['organization_id', 'attachment_id'], 'task_column_files_attachment_foreign')
                ->references(['organization_id', 'id'])->on('attachments')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `tasks` ADD CONSTRAINT `tasks_group_xor_parent_check` CHECK ((`parent_task_id` IS NULL AND `group_id` IS NOT NULL) OR (`parent_task_id` IS NOT NULL AND `group_id` IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_column_files');
        Schema::dropIfExists('task_column_labels');
        Schema::dropIfExists('task_column_people');
        Schema::dropIfExists('task_column_values');
        Schema::dropIfExists('tasks');

        $this->dropOptionCandidateKey();
        $this->dropCandidateKeyIfPresent('attachments', self::ATTACHMENT_CANDIDATE_KEY);
    }

    private function userForeign(Blueprint $table, string $column, string $name): void
    {
        $table->foreign(['organization_id', $column], $name)
            ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
    }

    /** @param list<string> $columns */
    private function addCandidateKey(string $table, array $columns, string $name): void
    {
        if ($this->hasUniqueIndex($table, $columns)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
    }

    /** @param list<string> $columns */
    private function hasUniqueIndex(string $table, array $columns): bool
    {
        $indexColumns = implode(',', $columns);

        return DB::table('information_schema.statistics')
            ->selectRaw('GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('non_unique', 0)
            ->groupBy('index_name')
            ->get()
            ->contains(fn (object $index): bool => $index->columns === $indexColumns);
    }

    private function dropCandidateKeyIfPresent(string $table, string $name): void
    {
        if (! DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists()) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
    }

    private function dropOptionCandidateKey(): void
    {
        if (! DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', 'task_column_options')
            ->where('index_name', self::OPTION_CANDIDATE_KEY)
            ->exists()) {
            return;
        }

        Schema::table('task_column_options', fn (Blueprint $table) => $table->dropForeign('task_column_options_definition_foreign'));
        Schema::table('task_column_options', fn (Blueprint $table) => $table->dropUnique(self::OPTION_CANDIDATE_KEY));
        Schema::table('task_column_options', function (Blueprint $table) {
            $table->foreign(['organization_id', 'board_id', 'column_definition_id'], 'task_column_options_definition_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_column_definitions')->restrictOnDelete();
        });
    }

};
