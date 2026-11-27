<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Saved views (Jose, 2026-11-09): mine stay mine, shared ones need the board's edit right. */
class TaskBoardViewsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_view_is_saved_listed_changed_and_kept_to_its_owner(): void
    {
        $t = $this->provisionTenant('Alpha');
        $board = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first());
        $url = "/api/v1/task-boards/{$board->id}/views";
        $config = ['match' => 'all', 'filters' => [['field' => 'status', 'op' => 'is', 'value' => 'stuck']],
            'sort' => [['field' => 'due', 'dir' => 'asc']], 'people' => [$t['admin']->id]];

        $mine = $this->signedInAs($t['admin'])->postJson($url, ['name' => 'My stuck', 'config' => $config])
            ->assertCreated()->assertJsonPath('data.shared', false)->json('data.id');
        $shared = $this->signedInAs($t['admin'])->postJson($url, ['name' => 'Team', 'shared' => true, 'config' => ['match' => 'any']])
            ->assertCreated()->assertJsonPath('data.shared', true)->json('data.id');
        $this->signedInAs($t['admin'])->postJson($url, ['name' => 'Bad', 'config' => ['filters' => [['field' => 'x', 'op' => 'like']]]])
            ->assertStatus(422);

        $this->signedInAs($t['admin'])->getJson("/api/v1/task-boards/{$board->id}")
            ->assertJsonPath('data.views.0.name', 'My stuck')
            ->assertJsonPath('data.views.0.config.sort.0.field', 'due')
            ->assertJsonCount(2, 'data.views');

        // Somebody else on the board sees the shared view only, and cannot change mine.
        $other = $this->userWithRole($t, 'Helper', ['tasks.view', 'tasks.create', 'tasks.edit']);
        $this->inTenant($t['org'], fn () => $board->members()->create(['organization_id' => $t['org']->id, 'user_id' => $other->id, 'role' => 'member']));
        $views = $this->signedInAs($other)->getJson("/api/v1/task-boards/{$board->id}")->json('data.views');
        $this->assertSame([$shared], array_column($views, 'id'));
        $this->signedInAs($other)->patchJson("{$url}/{$mine}", ['name' => 'Mine now'])->assertForbidden();

        $this->signedInAs($t['admin'])->patchJson("{$url}/{$mine}", ['name' => 'Stuck, mine'])->assertOk()->assertJsonPath('data.name', 'Stuck, mine');
        $this->signedInAs($t['admin'])->deleteJson("{$url}/{$mine}")->assertNoContent();
        $this->signedInAs($t['admin'])->getJson("/api/v1/task-boards/{$board->id}")->assertJsonCount(1, 'data.views');
    }
}
