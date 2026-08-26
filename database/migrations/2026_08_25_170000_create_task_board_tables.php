<?php

use App\Support\Tasks\BoardMemberRole;
use App\Support\Tasks\BoardVisibility;
use App\Support\Tasks\ColumnType;
use App\Support\Tasks\PriorityRank;
use App\Support\Tasks\StatusCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task boards are filed at one immutable place. Nullable compound keys alone
 * cannot prove organization-level equality, so place_key makes that shape a
 * non-null part of the folder/board foreign key (Tasks design §§4, 19).
 */
return new class extends Migration
{
    private const COMPANY_CANDIDATE_KEY = 'tasks_companies_organization_id_id_unique';
    private const FRANCHISE_CANDIDATE_KEY = 'tasks_franchises_organization_company_id_id_unique';
    private const USER_CANDIDATE_KEY = 'tasks_users_organization_id_id_unique';

    public function up(): void
    {
        $this->addCandidateKey('companies', ['organization_id', 'id'], self::COMPANY_CANDIDATE_KEY);
        $this->addCandidateKey('franchises', ['organization_id', 'company_id', 'id'], self::FRANCHISE_CANDIDATE_KEY);
        $this->addCandidateKey('users', ['organization_id', 'id'], self::USER_CANDIDATE_KEY);

        Schema::create('task_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $this->placeColumns($table, 'task_folders');
            $table->string('name', 191);
            $table->integer('position')->default(0);
            $table->string('place_key', 80)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'))");
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id', 'place_key'], 'task_folders_organization_id_id_place_key_unique');
            $table->unique(['organization_id', 'place_key', 'name'], 'task_folders_place_name_unique');
            $table->unique(['organization_id', 'place_key', 'active_position'], 'task_folders_place_active_position_unique');
        });

        Schema::create('task_boards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $this->placeColumns($table, 'task_boards');
            $table->uuid('folder_id')->nullable();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('visibility', 16);
            $table->unsignedInteger('structure_version')->default(1);
            $table->integer('position')->default(0);
            $table->uuid('created_by');
            $table->uuid('updated_by');
            $table->string('place_key', 80)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'))");
            $table->string('ordering_parent_key', 160)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'), ':', IFNULL(`folder_id`,'root'))");
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'task_boards_organization_id_id_unique');
            $table->unique(['organization_id', 'ordering_parent_key', 'active_position'], 'task_boards_parent_active_position_unique');
            $table->foreign(['organization_id', 'folder_id', 'place_key'], 'task_boards_folder_place_foreign')
                ->references(['organization_id', 'id', 'place_key'])->on('task_folders')->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'], 'task_boards_created_by_foreign')
                ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['organization_id', 'updated_by'], 'task_boards_updated_by_foreign')
                ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('task_board_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('user_id');
            $table->string('role', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'user_id'], 'task_board_members_board_user_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_board_members_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'], 'task_board_members_user_foreign')
                ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('task_board_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('user_id');
            $table->boolean('is_favorite')->default(false);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'user_id'], 'task_board_preferences_board_user_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_board_preferences_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'], 'task_board_preferences_user_foreign')
                ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('task_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->string('name', 191);
            $table->string('color', 32)->nullable();
            $table->integer('position')->default(0);
            $table->unsignedInteger('order_version')->default(1);
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'board_id', 'id'], 'task_groups_organization_board_id_unique');
            $table->unique(['board_id', 'name'], 'task_groups_board_name_unique');
            $table->unique(['board_id', 'active_position'], 'task_groups_board_active_position_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_groups_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
        });

        Schema::create('task_core_column_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->string('core_key', 64);
            $table->string('display_label', 191);
            $table->timestamps();

            $table->unique(['board_id', 'core_key'], 'task_core_column_settings_board_key_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_core_column_settings_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
        });

        Schema::create('task_status_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->string('name', 191);
            $table->string('color', 32)->nullable();
            $table->string('category', 16);
            $table->integer('position')->default(0);
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'board_id', 'id'], 'task_status_options_organization_board_id_unique');
            $table->unique(['board_id', 'name'], 'task_status_options_board_name_unique');
            $table->unique(['board_id', 'active_position'], 'task_status_options_board_active_position_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_status_options_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
        });

        Schema::create('task_priority_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->string('name', 191);
            $table->string('color', 32)->nullable();
            $table->string('rank', 16);
            $table->integer('position')->default(0);
            $table->string('active_rank', 16)->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `rank`, NULL)');
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'board_id', 'id'], 'task_priority_options_organization_board_id_unique');
            $table->unique(['board_id', 'name'], 'task_priority_options_board_name_unique');
            $table->unique(['board_id', 'active_rank'], 'task_priority_options_board_active_rank_unique');
            $table->unique(['board_id', 'active_position'], 'task_priority_options_board_active_position_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_priority_options_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
        });

        Schema::create('task_column_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->string('name', 191);
            $table->string('type', 16);
            $table->string('number_suffix', 32)->nullable();
            $table->integer('position')->default(0);
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'board_id', 'id'], 'task_column_definitions_organization_board_id_unique');
            $table->unique(['board_id', 'name'], 'task_column_definitions_board_name_unique');
            $table->unique(['board_id', 'active_position'], 'task_column_definitions_board_active_position_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_column_definitions_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
        });

        Schema::create('task_column_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('column_definition_id');
            $table->string('name', 191);
            $table->string('color', 32)->nullable();
            $table->integer('position')->default(0);
            $table->integer('active_position')->nullable()
                ->storedAs('IF(`archived_at` IS NULL, `position`, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['column_definition_id', 'name'], 'task_column_options_definition_name_unique');
            $table->unique(['column_definition_id', 'active_position'], 'task_column_options_definition_active_position_unique');
            $table->foreign(['organization_id', 'board_id', 'column_definition_id'], 'task_column_options_definition_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('task_column_definitions')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            $this->addChecks();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_column_options');
        Schema::dropIfExists('task_column_definitions');
        Schema::dropIfExists('task_priority_options');
        Schema::dropIfExists('task_status_options');
        Schema::dropIfExists('task_core_column_settings');
        Schema::dropIfExists('task_groups');
        Schema::dropIfExists('task_board_preferences');
        Schema::dropIfExists('task_board_members');
        Schema::dropIfExists('task_boards');
        Schema::dropIfExists('task_folders');

        $this->dropCandidateKeyIfPresent('users', self::USER_CANDIDATE_KEY);
        $this->dropCandidateKeyIfPresent('franchises', self::FRANCHISE_CANDIDATE_KEY);
        $this->dropCandidateKeyIfPresent('companies', self::COMPANY_CANDIDATE_KEY);
    }

    private function placeColumns(Blueprint $table, string $prefix): void
    {
        $table->uuid('company_id')->nullable();
        $table->uuid('franchise_id')->nullable();
        $table->foreign(['organization_id', 'company_id'], "{$prefix}_company_place_foreign")
            ->references(['organization_id', 'id'])->on('companies')->restrictOnDelete();
        $table->foreign(['organization_id', 'company_id', 'franchise_id'], "{$prefix}_franchise_place_foreign")
            ->references(['organization_id', 'company_id', 'id'])->on('franchises')->restrictOnDelete();
    }

    private function addChecks(): void
    {
        $this->addCheck('task_folders', 'task_folders_franchise_requires_company_check', '`franchise_id` IS NULL OR `company_id` IS NOT NULL');
        $this->addCheck('task_boards', 'task_boards_franchise_requires_company_check', '`franchise_id` IS NULL OR `company_id` IS NOT NULL');
        $this->addCheck('task_boards', 'task_boards_visibility_check', $this->inValues('visibility', BoardVisibility::values()));
        $this->addCheck('task_board_members', 'task_board_members_role_check', $this->inValues('role', BoardMemberRole::values()));
        $this->addCheck('task_status_options', 'task_status_options_category_check', $this->inValues('category', StatusCategory::values()));
        $this->addCheck('task_priority_options', 'task_priority_options_rank_check', $this->inValues('rank', PriorityRank::values()));
        $this->addCheck('task_column_definitions', 'task_column_definitions_type_check', $this->inValues('type', ColumnType::values()));
    }

    /** @param list<string> $values */
    private function inValues(string $column, array $values): string
    {
        $quoted = implode(', ', array_map(fn (string $value): string => DB::getPdo()->quote($value), $values));

        return "`{$column}` IN ({$quoted})";
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
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
        $exists = DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();

        if ($exists) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
        }
    }
};
