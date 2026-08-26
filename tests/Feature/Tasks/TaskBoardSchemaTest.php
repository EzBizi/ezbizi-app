<?php

namespace Tests\Feature\Tasks;

use App\Support\Tasks\BoardMemberRole;
use App\Support\Tasks\BoardVisibility;
use App\Support\Tasks\ColumnType;
use App\Support\Tasks\PriorityRank;
use App\Support\Tasks\StatusCategory;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskBoardSchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_backed_enums_expose_the_stable_task_values(): void
    {
        $this->assertSame(['scope', 'private'], BoardVisibility::values());
        $this->assertSame(['owner', 'member'], BoardMemberRole::values());
        $this->assertSame(['not_started', 'active', 'blocked', 'completed', 'cancelled'], StatusCategory::values());
        $this->assertSame(['low', 'normal', 'high', 'urgent'], PriorityRank::values());
        $this->assertSame(['text', 'number', 'checkbox', 'date', 'people', 'label', 'link', 'file'], ColumnType::values());
    }

    public function test_board_schema_has_the_required_tables_and_stable_defaults(): void
    {
        foreach ([
            'task_folders',
            'task_boards',
            'task_board_members',
            'task_board_preferences',
            'task_groups',
            'task_core_column_settings',
            'task_status_options',
            'task_priority_options',
            'task_column_definitions',
            'task_column_options',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasColumn('task_boards', 'owner_user_id'));
        $this->assertFalse(Schema::hasColumn('task_boards', 'scope_type'));
        $this->assertFalse(Schema::hasColumn('task_boards', 'scope_id'));

        $tenant = $this->provisionTenant('Alpha');
        $boardId = $this->insertBoard($tenant, companyId: null, franchiseId: null);
        $groupId = $this->insertGroup($tenant, $boardId, 0);

        $board = DB::table('task_boards')->find($boardId);
        $group = DB::table('task_groups')->find($groupId);

        $this->assertSame(1, $board->structure_version);
        $this->assertSame(1, $group->order_version);
    }

    public function test_parent_candidate_keys_and_compound_place_foreign_keys_reject_mismatches(): void
    {
        foreach ([
            'companies' => 'organization_id,id',
            'franchises' => 'organization_id,company_id,id',
            'users' => 'organization_id,id',
        ] as $table => $columns) {
            $this->assertTrue($this->hasUniqueIndex($table, $columns), "{$table} ({$columns})");
        }

        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $this->assertConstraintRejects(fn () => $this->insertFolder(
            $alpha,
            companyId: $beta['company']->id,
            franchiseId: null,
        ), 'task_folders_company_place_foreign');

        $this->assertConstraintRejects(fn () => $this->insertFolder(
            $alpha,
            companyId: $alpha['company']->id,
            franchiseId: $beta['franchise']->id,
        ), 'task_folders_franchise_place_foreign');

        $this->assertConstraintRejects(fn () => $this->insertFolder(
            $alpha,
            companyId: null,
            franchiseId: $alpha['franchise']->id,
        ), 'task_folders_franchise_requires_company_check');

        $this->assertConstraintRejects(fn () => $this->insertBoard(
            $alpha,
            companyId: null,
            franchiseId: null,
            position: 100,
            createdBy: $beta['admin']->id,
        ), 'task_boards_created_by_foreign');

        $this->assertConstraintRejects(fn () => $this->insertBoard(
            $alpha,
            companyId: null,
            franchiseId: null,
            position: 101,
            updatedBy: $beta['admin']->id,
        ), 'task_boards_updated_by_foreign');
    }

    public function test_folders_and_boards_support_all_place_shapes_and_require_exact_folder_equality(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $organizationFolder = $this->insertFolder($tenant, companyId: null, franchiseId: null);
        $companyFolder = $this->insertFolder($tenant, companyId: $tenant['company']->id, franchiseId: null);
        $franchiseFolder = $this->insertFolder($tenant, companyId: $tenant['company']->id, franchiseId: $tenant['franchise']->id);

        $organizationBoard = $this->insertBoard($tenant, companyId: null, franchiseId: null, folderId: $organizationFolder);
        $companyBoard = $this->insertBoard($tenant, companyId: $tenant['company']->id, franchiseId: null, folderId: $companyFolder);
        $franchiseBoard = $this->insertBoard($tenant, companyId: $tenant['company']->id, franchiseId: $tenant['franchise']->id, folderId: $franchiseFolder);

        $this->assertSame('org:*', DB::table('task_boards')->find($organizationBoard)->place_key);
        $this->assertSame("{$tenant['company']->id}:*", DB::table('task_boards')->find($companyBoard)->place_key);
        $this->assertSame("{$tenant['company']->id}:{$tenant['franchise']->id}", DB::table('task_boards')->find($franchiseBoard)->place_key);

        $this->assertConstraintRejects(fn () => $this->insertBoard(
            $tenant,
            companyId: $tenant['company']->id,
            franchiseId: null,
            folderId: $organizationFolder,
            position: 10,
        ), 'task_boards_folder_place_foreign');
    }

    public function test_membership_preferences_and_board_configuration_are_scoped_and_ordered(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boardId = $this->insertBoard($tenant, companyId: null, franchiseId: null);
        $memberId = (string) Str::uuid();
        $now = now();

        DB::table('task_board_members')->insert([
            'id' => $memberId,
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'user_id' => $tenant['admin']->id,
            'role' => 'owner',
            'is_active' => true,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('task_board_preferences')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'user_id' => $tenant['admin']->id,
            'is_favorite' => true,
            'last_opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertConstraintRejects(fn () => DB::table('task_board_members')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'user_id' => $tenant['admin']->id,
            'role' => 'member',
            'is_active' => true,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'task_board_members_board_user_unique');

        $secondUser = $this->userWithRole($tenant, 'Task Member');
        $this->assertConstraintRejects(fn () => DB::table('task_board_members')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'user_id' => $secondUser->id,
            'role' => 'administrator',
            'is_active' => true,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'task_board_members_role_check');

        $this->assertConstraintRejects(fn () => DB::table('task_board_preferences')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'user_id' => $tenant['admin']->id,
            'is_favorite' => false,
            'last_opened_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'task_board_preferences_board_user_unique');

        $this->insertGroup($tenant, $boardId, 0);
        $this->assertConstraintRejects(
            fn () => $this->insertGroup($tenant, $boardId, 0, 'Second group'),
            'task_groups_board_active_position_unique',
        );

        DB::table('task_core_column_settings')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'core_key' => 'status',
            'display_label' => 'Status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertConstraintRejects(fn () => DB::table('task_core_column_settings')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'core_key' => 'status',
            'display_label' => 'Stage',
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'task_core_column_settings_board_key_unique');

        $otherTenant = $this->provisionTenant('Beta');
        $this->assertConstraintRejects(fn () => DB::table('task_groups')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $otherTenant['org']->id,
            'board_id' => $boardId,
            'name' => 'Foreign board group',
            'color' => 'blue',
            'position' => 5,
            'order_version' => 1,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'task_groups_board_foreign');
    }

    public function test_visibility_status_priority_and_column_categories_are_constrained(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boardId = $this->insertBoard($tenant, companyId: null, franchiseId: null);

        $this->assertConstraintRejects(
            fn () => $this->insertBoard($tenant, companyId: null, franchiseId: null, visibility: 'team'),
            'task_boards_visibility_check',
        );

        $statusId = $this->insertStatus($tenant, $boardId, 0, 'active');
        $this->assertNotEmpty($statusId);
        $this->assertConstraintRejects(
            fn () => $this->insertStatus($tenant, $boardId, 1, 'waiting'),
            'task_status_options_category_check',
        );

        $priorityId = $this->insertPriority($tenant, $boardId, 0, 'normal');
        $this->assertNotEmpty($priorityId);
        $this->assertConstraintRejects(
            fn () => $this->insertPriority($tenant, $boardId, 1, 'none'),
            'task_priority_options_rank_check',
        );

        $columnId = $this->insertColumn($tenant, $boardId, 0, 'number');
        $this->assertSame('number', DB::table('task_column_definitions')->find($columnId)->type);
        $this->assertConstraintRejects(
            fn () => $this->insertColumn($tenant, $boardId, 1, 'formula'),
            'task_column_definitions_type_check',
        );
    }

    public function test_active_priority_ranks_and_sibling_positions_can_be_reused_after_archive(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boardId = $this->insertBoard($tenant, companyId: null, franchiseId: null, position: 0);
        $priorityId = $this->insertPriority($tenant, $boardId, 0, 'normal', 'Normal');

        $this->assertConstraintRejects(
            fn () => $this->insertPriority($tenant, $boardId, 1, 'normal', 'Replacement normal'),
            'task_priority_options_board_active_rank_unique',
        );

        DB::table('task_priority_options')->where('id', $priorityId)->update(['archived_at' => now()]);
        $this->assertNotEmpty($this->insertPriority($tenant, $boardId, 0, 'normal', 'Replacement normal'));

        DB::table('task_boards')->where('id', $boardId)->update(['archived_at' => now()]);
        $this->assertNotEmpty($this->insertBoard($tenant, companyId: null, franchiseId: null, position: 0));
    }

    public function test_folderless_board_ordering_is_exact_place_scoped_and_positions_are_signed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $firstBoard = $this->insertBoard($tenant, companyId: null, franchiseId: null, position: -100);

        $this->assertConstraintRejects(
            fn () => $this->insertBoard($tenant, companyId: null, franchiseId: null, position: -100),
            'task_boards_parent_active_position_unique',
        );

        $this->assertSame(-100, DB::table('task_boards')->find($firstBoard)->position);

        $folderId = $this->insertFolder($tenant, companyId: null, franchiseId: null, position: -99);
        $groupId = $this->insertGroup($tenant, $firstBoard, -98);
        $statusId = $this->insertStatus($tenant, $firstBoard, -97, 'active');
        $priorityId = $this->insertPriority($tenant, $firstBoard, -96, 'normal');
        $columnId = $this->insertColumn($tenant, $firstBoard, -95, 'label');
        $optionId = (string) Str::uuid();

        DB::table('task_column_options')->insert([
            'id' => $optionId,
            'organization_id' => $tenant['org']->id,
            'board_id' => $firstBoard,
            'column_definition_id' => $columnId,
            'name' => 'Negative temporary option',
            'color' => 'blue',
            'position' => -94,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'task_folders' => $folderId,
            'task_groups' => $groupId,
            'task_status_options' => $statusId,
            'task_priority_options' => $priorityId,
            'task_column_definitions' => $columnId,
            'task_column_options' => $optionId,
        ] as $table => $id) {
            $this->assertLessThan(0, DB::table($table)->find($id)->position, $table);
        }

        foreach ([
            'task_folders',
            'task_boards',
            'task_groups',
            'task_status_options',
            'task_priority_options',
            'task_column_definitions',
            'task_column_options',
        ] as $table) {
            $column = DB::table('information_schema.columns')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', $table)
                ->where('column_name', 'position')
                ->value('column_type');

            $this->assertStringNotContainsString('unsigned', $column, $table);
        }
    }

    /** @param array{org: object, admin: object, company: object, franchise: object} $tenant */
    private function insertFolder(array $tenant, ?string $companyId, ?string $franchiseId, int $position = 0): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_folders')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'company_id' => $companyId,
            'franchise_id' => $franchiseId,
            'name' => "Folder {$id}",
            'position' => $position,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object, admin: object, company: object, franchise: object} $tenant */
    private function insertBoard(
        array $tenant,
        ?string $companyId,
        ?string $franchiseId,
        ?string $folderId = null,
        string $visibility = 'scope',
        int $position = 0,
        ?string $createdBy = null,
        ?string $updatedBy = null,
    ): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_boards')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'company_id' => $companyId,
            'franchise_id' => $franchiseId,
            'folder_id' => $folderId,
            'name' => "Board {$id}",
            'description' => null,
            'visibility' => $visibility,
            'position' => $position,
            'created_by' => $createdBy ?? $tenant['admin']->id,
            'updated_by' => $updatedBy ?? $tenant['admin']->id,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertGroup(array $tenant, string $boardId, int $position, ?string $name = null): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_groups')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'name' => $name ?? "Group {$position}",
            'color' => 'blue',
            'position' => $position,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertStatus(array $tenant, string $boardId, int $position, string $category): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_status_options')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'name' => "Status {$position}",
            'color' => 'blue',
            'category' => $category,
            'position' => $position,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertPriority(array $tenant, string $boardId, int $position, string $rank, ?string $name = null): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_priority_options')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'name' => $name ?? "Priority {$position}",
            'color' => 'blue',
            'rank' => $rank,
            'position' => $position,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertColumn(array $tenant, string $boardId, int $position, string $type): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_column_definitions')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'name' => "Column {$position}",
            'type' => $type,
            'number_suffix' => $type === 'number' ? 'hrs' : null,
            'position' => $position,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function hasUniqueIndex(string $table, string $columns): bool
    {
        return DB::table('information_schema.statistics')
            ->selectRaw('index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('non_unique', 0)
            ->groupBy('index_name')
            ->get()
            ->contains(fn (object $index): bool => $index->columns === $columns);
    }

    private function assertConstraintRejects(Closure $write, ?string $constraint = null): void
    {
        try {
            $write();
            $this->fail('Expected MySQL to reject the invalid row.');
        } catch (QueryException $exception) {
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }
}
