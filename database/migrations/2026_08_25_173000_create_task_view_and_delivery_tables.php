<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('owner_user_id')->nullable();
            $table->enum('visibility', ['personal', 'shared']);
            $table->string('name', 191);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_permanent_main')->default(false);
            $table->json('configuration');
            $table->unsignedInteger('version')->default(1);
            $table->integer('position')->default(0);
            $table->string('scope_key', 36)
                ->storedAs("IF(`visibility` = 'personal', `owner_user_id`, 'shared')");
            $table->uuid('permanent_main_board_id')->nullable()
                ->storedAs('IF(`is_permanent_main`, `board_id`, NULL)');
            $table->timestamps();

            $table->unique(['organization_id', 'board_id', 'id'], 'task_views_organization_board_id_unique');
            $table->unique(['board_id', 'scope_key', 'name'], 'task_views_scope_name_unique');
            $table->unique(['board_id', 'scope_key', 'position'], 'task_views_scope_position_unique');
            $table->unique('permanent_main_board_id', 'task_views_board_main_unique');
            $table->foreign(['organization_id', 'board_id'], 'task_views_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
            $this->userForeign($table, 'owner_user_id', 'task_views_owner_foreign');
        });

        Schema::create('task_view_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('view_id');
            $table->uuid('user_id');
            $table->json('collapsed_group_ids');
            $table->enum('density', ['compact', 'comfortable'])->default('compact');
            $table->boolean('is_last_active')->default(false);
            $table->integer('scroll_left')->default(0);
            $table->integer('scroll_top')->default(0);
            $table->uuid('last_active_user_id')->nullable()
                ->storedAs('IF(`is_last_active`, `user_id`, NULL)');
            $table->timestamps();

            $table->unique(['view_id', 'user_id'], 'task_view_preferences_view_user_unique');
            $table->unique(['board_id', 'last_active_user_id'], 'task_view_preferences_last_active_unique');
            $this->viewForeign($table, 'view_id', 'task_view_preferences_view_foreign');
            $this->userForeign($table, 'user_id', 'task_view_preferences_user_foreign');
        });

        Schema::create('task_overdue_occurrences', function (Blueprint $table) {
            $this->taskIdentity($table);
            $table->unsignedInteger('due_revision');
            $table->date('due_on');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['task_id', 'due_revision'], 'task_overdue_occurrences_task_revision_unique');
            $this->taskForeign($table, 'task_overdue_occurrences_task_foreign');
        });

        Schema::create('task_due_reminder_occurrences', function (Blueprint $table) {
            $this->taskIdentity($table);
            $table->uuid('recipient_user_id');
            $table->unsignedInteger('due_revision');
            $table->unsignedInteger('lead_hours');
            $table->date('due_on');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['task_id', 'recipient_user_id', 'due_revision', 'lead_hours'],
                'task_due_reminders_occurrence_unique',
            );
            $this->taskForeign($table, 'task_due_reminders_task_foreign');
            $this->userForeign($table, 'recipient_user_id', 'task_due_reminders_recipient_foreign');
        });

        Schema::create('task_notification_intents', function (Blueprint $table) {
            $this->taskIdentity($table);
            $table->uuid('recipient_user_id');
            $table->string('kind', 80);
            $table->uuid('source_occurrence_key');
            $table->enum('status', ['pending', 'processing', 'delivered', 'suppressed', 'failed'])
                ->default('pending');
            $table->timestamp('available_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'recipient_user_id', 'kind', 'source_occurrence_key'],
                'task_notification_intents_recipient_occurrence_unique',
            );
            $table->index(['status', 'available_at', 'id'], 'task_notification_intents_dispatch_index');
            $this->taskForeign($table, 'task_notification_intents_task_foreign');
            $this->userForeign($table, 'recipient_user_id', 'task_notification_intents_recipient_foreign');
        });

        Schema::create('task_import_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('active_view_id');
            $table->uuid('actor_user_id');
            $table->char('token_hash', 64);
            $table->uuid('nonce');
            $table->string('disk', 80);
            $table->string('path', 1024);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->json('normalized_mapping');
            $table->unsignedInteger('structure_version');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'task_import_sessions_token_unique');
            $table->unique('nonce', 'task_import_sessions_nonce_unique');
            $table->index(['expires_at', 'consumed_at', 'id'], 'task_import_sessions_expiry_index');
            $this->viewForeign($table, 'active_view_id', 'task_import_sessions_view_foreign');
            $this->userForeign($table, 'actor_user_id', 'task_import_sessions_actor_foreign');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('delivery_key')->nullable()->after('source');
            $table->unique(
                ['organization_id', 'user_id', 'source', 'delivery_key'],
                'notifications_delivery_unique',
            );
        });

        if (DB::getDriverName() === 'mysql') {
            $this->addChecksAndPermanentMainTriggers();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS task_views_main_marker_immutable');
            DB::unprepared('DROP TRIGGER IF EXISTS task_views_main_permanent');
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_delivery_unique');
            $table->dropColumn('delivery_key');
        });
        Schema::dropIfExists('task_import_sessions');
        Schema::dropIfExists('task_notification_intents');
        Schema::dropIfExists('task_due_reminder_occurrences');
        Schema::dropIfExists('task_overdue_occurrences');
        Schema::dropIfExists('task_view_preferences');
        Schema::dropIfExists('task_views');
    }

    private function taskIdentity(Blueprint $table): void
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

    private function viewForeign(Blueprint $table, string $column, string $name): void
    {
        $table->foreign(['organization_id', 'board_id', $column], $name)
            ->references(['organization_id', 'board_id', 'id'])->on('task_views')->restrictOnDelete();
    }

    private function userForeign(Blueprint $table, string $column, string $name): void
    {
        $table->foreign(['organization_id', $column], $name)
            ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
    }

    private function addChecksAndPermanentMainTriggers(): void
    {
        DB::statement("ALTER TABLE `task_views` ADD CONSTRAINT `task_views_owner_visibility_check` CHECK ((`visibility` = 'personal' AND `owner_user_id` IS NOT NULL) OR (`visibility` = 'shared' AND `owner_user_id` IS NULL))");
        DB::statement("ALTER TABLE `task_views` ADD CONSTRAINT `task_views_main_shared_check` CHECK (`is_permanent_main` = 0 OR `visibility` = 'shared')");
        DB::statement("ALTER TABLE `task_notification_intents` ADD CONSTRAINT `task_notification_intents_claim_check` CHECK ((`status` = 'processing' AND `claim_token` IS NOT NULL AND `claimed_until` IS NOT NULL) OR (`status` <> 'processing' AND `claim_token` IS NULL AND `claimed_until` IS NULL))");
        DB::unprepared("CREATE TRIGGER `task_views_main_marker_immutable` BEFORE UPDATE ON `task_views` FOR EACH ROW BEGIN IF OLD.`is_permanent_main` <> NEW.`is_permanent_main` THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permanent Main marker is immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER `task_views_main_permanent` BEFORE DELETE ON `task_views` FOR EACH ROW BEGIN IF OLD.`is_permanent_main` = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permanent Main view cannot be deleted'; END IF; END");
    }
};
