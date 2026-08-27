<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MyTasksApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_my_tasks_and_quick_search_use_current_view_visible_boards_without_private_leaks(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $worker = $this->userWithRole($alpha, 'Worker', ['tasks.view', 'tasks.edit']);
        $worker->forceFill(['preferences' => $alpha['admin']->preferences])->save();
        $this->actingAs($alpha['admin']->fresh());
        $visible = $this->createBoard('Visible board', 'scope');
        $private = $this->createBoard('Secret board', 'private');
        $privateMembershipId = $this->postJson("/api/v1/tasks/boards/{$private->id}/members", [
            'user_id' => $worker->id, 'role' => 'member',
        ])->assertCreated()->json('data.id');
        $owned = $this->createTask($visible, 'Owned visible task', $worker->id);
        $unowned = $this->createTask($visible, 'Searchable shared work', $alpha['admin']->id);
        $this->createTask($private, 'Secret task phrase', $worker->id);
        $this->deleteJson("/api/v1/tasks/boards/{$private->id}/members/{$privateMembershipId}")
            ->assertOk();

        $this->signedInAs($worker);
        $response = $this->getJson('/api/v1/tasks/my')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $owned->id);
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);
        $cached = $this->getJson('/api/v1/tasks/my', ['If-None-Match' => $etag]);
        $this->assertSame($etag, $cached->headers->get('ETag'));
        $cached->assertNoContent(304);

        app(TenantContext::class)->set($alpha['org']);
        $visible->forceFill(['name' => 'Renamed visible board'])->save();
        $this->getJson('/api/v1/tasks/my', ['If-None-Match' => $etag])->assertOk()
            ->assertJsonPath('data.0.board.name', 'Renamed visible board');

        $this->getJson('/api/v1/tasks/search?q=Searchable')->assertOk()
            ->assertJsonPath('data.0.task_id', $unowned->id)
            ->assertJsonPath('data.0.deep_link', "/tasks/boards/{$visible->id}/tasks/{$unowned->id}");
        $this->getJson('/api/v1/tasks/search?q=Secret')->assertOk()->assertJsonCount(0, 'data');
    }

    private function createBoard(string $name, string $visibility): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', compact('name', 'visibility'))->assertCreated()->json('data.id');
        app(TenantContext::class)->set(auth()->user()->organization);

        return TaskBoard::findOrFail($id);
    }

    private function createTask(TaskBoard $board, string $title, string $ownerId): Task
    {
        app(TenantContext::class)->set(auth()->user()->organization);
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $board->groups()->active()->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'owner_user_id' => $ownerId,
            'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set(auth()->user()->organization);

        return Task::findOrFail($id);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
