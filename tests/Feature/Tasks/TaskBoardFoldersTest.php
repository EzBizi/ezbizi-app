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

        // Deleting a folder leaves its boards loose.
        $this->signedInAs($maker)->deleteJson("/api/v1/task-board-folders/{$top}")->assertNoContent();
        $row = collect($this->signedInAs($maker)->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $board);
        $this->assertNull($row['folder_id']);
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
}
