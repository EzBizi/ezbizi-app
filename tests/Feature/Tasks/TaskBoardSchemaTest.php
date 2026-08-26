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
        ));

        $this->assertConstraintRejects(fn () => $this->insertFolder(
            $alpha,
            companyId: $alpha['company']->id,
            franchiseId: $beta['franchise']->id,
        ));

        $this->assertConstraintRejects(fn () => $this->insertFolder(
            $alpha,
            companyId: null,
            franchiseId: $alpha['franchise']->id,
        ));
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
        ));
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
        ]));

        $this->assertConstraintRejects(fn () => DB::table('task_board_members')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'user_id' => $tenant['admin']->id,
            'role' => 'administrator',
            'is_active' => true,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertConstraintRejects(fn () => DB::table('task_board_preferences')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'user_id' => $tenant['admin']->id,
            'is_favorite' => false,
            'last_opened_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->insertGroup($tenant, $boardId, 0);
        $this->assertConstraintRejects(fn () => $this->insertGroup($tenant, $boardId, 0));

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
        ]));
    }

    public function test_visibility_status_priority_and_column_categories_are_constrained(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boardId = $this->insertBoard($tenant, companyId: null, franchiseId: null);

        $this->assertConstraintRejects(fn () => $this->insertBoard($tenant, companyId: null, franchiseId: null, visibility: 'team'));

        $statusId = $this->insertStatus($tenant, $boardId, 0, 'active');
        $this->assertNotEmpty($statusId);
        $this->assertConstraintRejects(fn () => $this->insertStatus($tenant, $boardId, 1, 'waiting'));

        $priorityId = $this->insertPriority($tenant, $boardId, 0, 'normal');
        $this->assertNotEmpty($priorityId);
        $this->assertConstraintRejects(fn () => $this->insertPriority($tenant, $boardId, 1, 'none'));

        $columnId = $this->insertColumn($tenant, $boardId, 0, 'number');
        $this->assertSame('number', DB::table('task_column_definitions')->find($columnId)->type);
        $this->assertConstraintRejects(fn () => $this->insertColumn($tenant, $boardId, 1, 'formula'));
    }

    /** @param array{org: object, admin: object, company: object, franchise: object} $tenant */
    private function insertFolder(array $tenant, ?string $companyId, ?string $franchiseId): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_folders')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'company_id' => $companyId,
            'franchise_id' => $franchiseId,
            'name' => "Folder {$id}",
            'position' => 0,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object, admin: object, company: object, franchise: object} $tenant */
    private function insertBoard(array $tenant, ?string $companyId, ?string $franchiseId, ?string $folderId = null, string $visibility = 'scope'): string
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
            'position' => 0,
            'created_by' => $tenant['admin']->id,
            'updated_by' => $tenant['admin']->id,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertGroup(array $tenant, string $boardId, int $position): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_groups')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'name' => "Group {$position}",
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
    private function insertPriority(array $tenant, string $boardId, int $position, string $rank): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('task_priority_options')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'name' => "Priority {$position}",
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

    private function assertConstraintRejects(Closure $write): void
    {
        try {
            $write();
            $this->fail('Expected MySQL to reject the invalid row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
