<?php

namespace Tests\Feature\Tasks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Folders and colours on the Task Board list (Jose 11-13, Monday parity). */
class TaskBoardFoldersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Row 66 (Jose 11-25): a folder says its company; the company's Monday "… - Operation" board is its company board. */
    public function test_a_folder_named_for_its_company_carries_it_and_the_operation_board_is_the_company_board(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $maker = $this->userWithRole($tenant, 'Maker', ['tasks.view', 'tasks.edit', 'tasks.create_boards', 'tasks.view_all']);
        $api = fn () => $this->signedInAs($maker);
        $companyName = $tenant['company']->name;
        $list = fn () => collect($api()->getJson('/api/v1/task-boards')->assertOk()->json('data'))->keyBy('name');
        $companyBoard = $list()->first(fn ($b) => $b['kind'] === 'company');
        $this->assertTrue($companyBoard['company_board'], 'no Operation board yet: the company board is it');

        $top = $api()->postJson('/api/v1/task-board-folders', ['name' => strtoupper($companyName)])->assertCreated()->json('data.id');
        $sub = $api()->postJson('/api/v1/task-board-folders', ['name' => 'Partners', 'parent_id' => $top])->assertCreated()->json('data.id');
        $other = $api()->postJson('/api/v1/task-board-folders', ['name' => 'Templates'])->assertCreated()->json('data.id');
        $op = $api()->postJson('/api/v1/task-boards', ['name' => 'Alpha - Operation', 'visibility' => 'main'])->assertCreated()->json('data.id');
        $api()->patchJson("/api/v1/task-boards/{$op}", ['folder_id' => $top])->assertOk();

        $folders = collect($api()->getJson('/api/v1/task-board-folders')->json('data'))->keyBy('id');
        $this->assertSame($tenant['company']->id, $folders[$top]['company_id']);
        $this->assertSame($tenant['company']->id, $folders[$sub]['company_id'], 'a sub-folder is its parent\'s company');
        $this->assertNull($folders[$other]['company_id']);

        $boards = $list();
        $this->assertTrue($boards['Alpha - Operation']['company_board']);
        $this->assertSame($tenant['company']->id, $boards['Alpha - Operation']['company_id']);
        $this->assertFalse(collect($boards)->firstWhere('id', $companyBoard['id'])['company_board'], 'one company board per company');
    }

    public function test_folders_nest_colour_and_hold_boards_for_the_whole_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $maker = $this->userWithRole($tenant, 'Maker', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $reader = $this->userWithRole($tenant, 'Reader', ['tasks.view']);
        $board = $this->signedInAs($maker)->postJson('/api/v1/task-boards', [
            'name' => 'Partners', 'visibility' => 'main', 'franchise_id' => $tenant['franchise']->id,
        ])->assertCreated()->json('data.id');

        $top = $this->signedInAs($maker)->postJson('/api/v1/task-board-folders', ['name' => 'A1 Chimney', 'colour' => '#e2445c'])
            ->assertCreated()->json('data.id');
        $sub = $this->signedInAs($maker)->postJson('/api/v1/task-board-folders', ['name' => 'Partners', 'parent_id' => $top])
            ->assertCreated()->json('data.id');
        $this->signedInAs($maker)->postJson('/api/v1/task-board-folders', ['name' => 'Bad', 'colour' => 'purple'])->assertUnprocessable();
        // A folder cannot go inside its own child.
        $this->signedInAs($maker)->patchJson("/api/v1/task-board-folders/{$top}", ['parent_id' => $sub])->assertStatus(422);

        $this->signedInAs($maker)->patchJson("/api/v1/task-boards/{$board}", ['folder_id' => $sub, 'colour' => '#00c875', 'position' => 2])->assertOk();

        // Everybody in the organization sees the same arrangement.
        $this->assertCount(2, $this->signedInAs($reader)->getJson('/api/v1/task-board-folders')->assertOk()->json('data'));
        $row = collect($this->signedInAs($maker)->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $board);
        $this->assertSame(['folder_id' => $sub, 'colour' => '#00c875', 'position' => 2], array_intersect_key($row, array_flip(['folder_id', 'colour', 'position'])));

        // Arranging needs the right to make boards.
        $this->signedInAs($reader)->postJson('/api/v1/task-board-folders', ['name' => 'Nope'])->assertForbidden();

        // Deleting a folder keeps what is in it (audit 11-27): its subfolder moves up, the board stays in it.
        $this->signedInAs($maker)->deleteJson("/api/v1/task-board-folders/{$top}")->assertNoContent();
        $row = collect($this->signedInAs($maker)->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $board);
        $this->assertSame($sub, $row['folder_id']);
        $folders = collect($this->signedInAs($maker)->getJson('/api/v1/task-board-folders')->json('data'));
        $this->assertSame([$sub], $folders->pluck('id')->all());
        $this->assertNull($folders->first()['parent_id']);
        // And the last one leaves the board loose.
        $this->signedInAs($maker)->deleteJson("/api/v1/task-board-folders/{$sub}")->assertNoContent();
        $row = collect($this->signedInAs($maker)->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $board);
        $this->assertNull($row['folder_id']);
    }

    /** Jose 11-24 (Adeline): a folder shows only on the way to a board you can open. */
    public function test_a_reader_sees_only_the_folders_that_lead_to_a_board_they_can_open(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $maker = $this->userWithRole($tenant, 'Maker', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $reader = $this->userWithRole($tenant, 'Reader', ['tasks.view']);
        $api = fn () => $this->signedInAs($maker);
        $folder = fn (string $name, ?string $parent = null) => $api()->postJson('/api/v1/task-board-folders', ['name' => $name, 'parent_id' => $parent])->assertCreated()->json('data.id');
        $a1 = $folder('A1 Chimney');
        $field = $folder('Field Management', $a1);
        $pm = $folder('Project Management', $field);
        $marketing = $folder('Marketing', $a1);
        $folder('Office Management');
        $open = $api()->postJson('/api/v1/task-boards', ['name' => 'Ohio PM', 'visibility' => 'main', 'franchise_id' => $tenant['franchise']->id])->assertCreated()->json('data.id');
        $private = $api()->postJson('/api/v1/task-boards', ['name' => 'Ads', 'visibility' => 'private', 'franchise_id' => $tenant['franchise']->id])->assertCreated()->json('data.id');
        $api()->patchJson("/api/v1/task-boards/{$open}", ['folder_id' => $pm])->assertOk();
        $api()->patchJson("/api/v1/task-boards/{$private}", ['folder_id' => $marketing])->assertOk();

        $names = fn ($user) => collect($this->signedInAs($user)->getJson('/api/v1/task-board-folders')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['A1 Chimney', 'Field Management', 'Project Management'], $names($reader));
        // Whoever arranges the list sees every folder, empty ones too.
        $this->assertCount(5, $names($maker));
    }

    public function test_another_organizations_folder_is_not_found(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $a = $this->userWithRole($alpha, 'A', ['tasks.view', 'tasks.create_boards']);
        $b = $this->userWithRole($beta, 'B', ['tasks.view', 'tasks.create_boards']);
        $folder = $this->signedInAs($a)->postJson('/api/v1/task-board-folders', ['name' => 'Mine'])->json('data.id');

        $this->signedInAs($b)->postJson('/api/v1/task-board-folders', ['name' => 'Sneaky', 'parent_id' => $folder])->assertUnprocessable();
        $this->signedInAs($b)->patchJson("/api/v1/task-board-folders/{$folder}", ['name' => 'Taken'])->assertNotFound();
        $this->assertSame([], $this->signedInAs($b)->getJson('/api/v1/task-board-folders')->json('data'));
    }

    public function test_company_and_branch_group_colours_are_the_organizations_not_one_persons(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $maker = $this->userWithRole($alpha, 'Maker', ['tasks.view', 'tasks.create_boards']);
        $reader = $this->userWithRole($alpha, 'Reader', ['tasks.view']);
        $other = $this->userWithRole($beta, 'Other', ['tasks.view', 'tasks.create_boards']);

        $this->signedInAs($maker)->patchJson('/api/v1/task-board-group-colours', ['group' => 'company:A1 Chimney', 'colour' => '#00C875'])->assertOk();
        $this->signedInAs($maker)->patchJson('/api/v1/task-board-group-colours', ['group' => 'branch:f-1', 'colour' => '#e2445c'])->assertOk();
        $this->signedInAs($maker)->patchJson('/api/v1/task-board-group-colours', ['group' => 'company:A1 Chimney', 'colour' => 'purple'])->assertUnprocessable();
        $this->signedInAs($reader)->patchJson('/api/v1/task-board-group-colours', ['group' => 'branch:f-1', 'colour' => null])->assertForbidden();

        // A reader sees the colours the maker chose; another organization sees none.
        $this->signedInAs($reader)->getJson('/api/v1/task-board-group-colours')->assertOk()
            ->assertExactJson(['data' => ['company:A1 Chimney' => '#00c875', 'branch:f-1' => '#e2445c']]);
        $this->signedInAs($other)->getJson('/api/v1/task-board-group-colours')->assertOk()->assertExactJson(['data' => []]);

        $this->signedInAs($maker)->patchJson('/api/v1/task-board-group-colours', ['group' => 'branch:f-1', 'colour' => null])->assertOk();
        $this->signedInAs($reader)->getJson('/api/v1/task-board-group-colours')
            ->assertExactJson(['data' => ['company:A1 Chimney' => '#00c875']]);
    }

    /** Jose 11-20: a reorder showed after ~1 min, each PATCH answering with the whole board. Arranging answers light. */
    public function test_arranging_a_board_answers_with_just_its_place(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $maker = $this->userWithRole($tenant, 'Maker', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $board = $this->signedInAs($maker)->postJson('/api/v1/task-boards', [
            'name' => 'Partners', 'visibility' => 'main', 'franchise_id' => $tenant['franchise']->id,
        ])->assertCreated()->json('data.id');

        $answer = $this->signedInAs($maker)->patchJson("/api/v1/task-boards/{$board}", ['position' => 3])->assertOk()->json('data');
        $this->assertSame(['id' => $board, 'folder_id' => null, 'colour' => null, 'position' => 3], $answer);
        // A rename still answers with the board.
        $this->assertArrayHasKey('groups', $this->signedInAs($maker)->patchJson("/api/v1/task-boards/{$board}", ['name' => 'Partners 2'])->assertOk()->json('data'));
    }

    /** Audit 11-27: arranging rights do not carry a board setting along in the same request. */
    public function test_a_mixed_patch_needs_every_part_allowed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->signedInAs($tenant['admin'])->postJson('/api/v1/task-boards', [
            'name' => 'Partners', 'visibility' => 'main', 'franchise_id' => $tenant['franchise']->id,
        ])->assertCreated()->json('data.id');
        $maker = $this->userWithRole($tenant, 'Maker', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);

        $this->signedInAs($maker)->patchJson("/api/v1/task-boards/{$board}", ['position' => 3, 'edit_mode' => 'assignee'])->assertForbidden();
        $this->signedInAs($maker)->patchJson("/api/v1/task-boards/{$board}", ['position' => 3, 'name' => 'Mine now'])->assertForbidden();
        $this->signedInAs($maker)->patchJson("/api/v1/task-boards/{$board}", ['position' => 3])->assertOk();
        $this->assertDatabaseHas('task_boards', ['id' => $board, 'name' => 'Partners', 'edit_mode' => 'everyone']);
    }
}
