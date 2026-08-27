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

class TaskDetailsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_detail_has_separate_core_detail_etag_and_rechecks_private_access_before_304(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Private detail', 'visibility' => 'private',
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($alpha['org']);
        $board = TaskBoard::findOrFail($boardId);
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $board->groups()->active()->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => 'Inspect detail', 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($alpha['org']);
        $task = Task::findOrFail($taskId);
        $childId = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => 'Detail child', 'parent_task_id' => $task->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($alpha['org']);
        $child = Task::findOrFail($childId);
        $url = "/api/v1/tasks/boards/{$board->id}/tasks/{$task->id}";

        $first = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.detail_version', 1);
        $etag = $first->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson($url)->assertNoContent(304);
        $this->patchJson("/api/v1/tasks/{$child->id}", [
            'expected_version' => $child->version, 'title' => 'Changed detail child',
        ])->assertOk();
        $this->withHeader('If-None-Match', $etag)->getJson($url)->assertOk()
            ->assertJsonPath('data.subtasks.0.title', 'Changed detail child');
        app(TenantContext::class)->set($alpha['org']);
        $task->forceFill(['detail_version' => 2])->save();
        $this->withHeader('If-None-Match', $etag)->getJson($url)->assertOk()
            ->assertJsonPath('data.detail_version', 2);

        app(TenantContext::class)->set($alpha['org']);
        $membership = $board->members()->where('user_id', $alpha['admin']->id)->firstOrFail();
        $membership->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $this->withHeader('If-None-Match', $etag)->getJson($url)->assertNotFound();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
