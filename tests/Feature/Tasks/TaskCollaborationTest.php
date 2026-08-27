<?php

namespace Tests\Feature\Tasks;

use App\Models\Assignment;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskNotificationIntent;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateVersion;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskNotificationIntentDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskCollaborationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->tenant = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
        $this->signedInAs($this->tenant['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $group = $this->board->groups()->active()->firstOrFail();
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Inspect site', 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->task = Task::findOrFail($taskId);
    }

    public function test_updates_are_idempotent_author_owned_versioned_and_mention_explicit_eligible_users(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'Commenter', ['tasks.view', 'tasks.comment']);
        $this->signedInAs($commenter);
        $payload = [
            'body' => '<p>Hello <strong>team</strong>.</p>',
            'mention_user_ids' => [$this->tenant['admin']->id],
            'idempotency_key' => 'compose-1',
        ];
        $first = $this->postJson("/api/v1/tasks/{$this->task->id}/updates", $payload)->assertCreated();
        $second = $this->postJson("/api/v1/tasks/{$this->task->id}/updates", $payload)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->enterTenant();
        $update = TaskUpdate::findOrFail($first->json('data.id'));
        $this->assertSame(1, TaskUpdate::where('task_id', $this->task->id)->count());
        $this->assertSame([$this->tenant['admin']->id], $update->mentions()->pluck('user_id')->all());
        $this->assertSame(1, TaskNotificationIntent::where('kind', 'task.mentioned')->count());

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->patchJson("/api/v1/tasks/{$this->task->id}/updates/{$update->id}", [
            'expected_update_version' => 1, 'body' => 'Hijacked', 'mention_user_ids' => [],
        ])->assertForbidden();

        $this->signedInAs($commenter);
        $this->patchJson("/api/v1/tasks/{$this->task->id}/updates/{$update->id}", [
            'expected_update_version' => 1,
            'body' => '<p>Edited safely.</p>',
            'mention_user_ids' => [],
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->enterTenant();
        $this->assertSame(1, TaskUpdateVersion::where('update_id', $update->id)->count());

        $this->signedInAs($commenter);
        $this->postJson("/api/v1/tasks/{$this->task->id}/updates/{$update->id}/archive", [
            'expected_update_version' => 2,
        ])->assertOk()->assertJsonPath('data.archived', true);
        $this->enterTenant();
        $this->assertSame(2, TaskUpdateVersion::where('update_id', $update->id)->count());
        $this->assertGreaterThan(1, $this->task->fresh()->detail_version);
    }

    public function test_collaboration_and_personal_watch_preferences_remain_separate(): void
    {
        $collaborator = $this->userWithRole($this->tenant, 'Collaborator', ['tasks.view']);
        $this->signedInAs($collaborator);
        $this->putJson("/api/v1/tasks/{$this->task->id}/watch", ['preference' => 'muted'])
            ->assertOk()->assertJsonPath('data.preference', 'muted');

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->putJson("/api/v1/tasks/{$this->task->id}/collaborators", [
            'expected_version' => $this->task->fresh()->version,
            'user_ids' => [$collaborator->id],
        ])->assertOk();
        $this->enterTenant();
        $this->assertSame('muted', $this->task->watchPreferences()->where('user_id', $collaborator->id)->value('preference'));

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->putJson("/api/v1/tasks/{$this->task->id}/collaborators", [
            'expected_version' => $this->task->fresh()->version,
            'user_ids' => [],
        ])->assertOk();
        $this->enterTenant();
        $this->assertSame('muted', $this->task->watchPreferences()->where('user_id', $collaborator->id)->value('preference'));

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->patchJson("/api/v1/tasks/{$this->task->id}", [
            'expected_version' => $this->task->fresh()->version,
            'owner_user_id' => $collaborator->id,
        ])->assertOk();
        $this->signedInAs($collaborator);
        $this->putJson("/api/v1/tasks/{$this->task->id}/watch", ['preference' => 'muted'])
            ->assertUnprocessable();
    }

    public function test_notification_delivery_is_deduplicated_and_revoked_work_is_filtered_before_count_and_read(): void
    {
        $recipient = $this->userWithRole($this->tenant, 'Recipient', ['tasks.view']);
        $this->enterTenant();
        $intent = TaskNotificationIntent::create([
            'board_id' => $this->board->id,
            'task_id' => $this->task->id,
            'recipient_user_id' => $recipient->id,
            'kind' => 'task.assigned',
            'source_occurrence_key' => $this->task->id,
            'available_at' => now(),
        ]);
        $this->task->forceFill(['owner_user_id' => $recipient->id])->save();

        $dispatcher = app(TaskNotificationIntentDispatcher::class);
        $this->assertTrue($dispatcher->dispatchOne($intent->id));
        $this->assertFalse($dispatcher->dispatchOne($intent->id));
        $this->enterTenant();
        $notification = Notification::where('delivery_key', $intent->id)->firstOrFail();
        $this->assertSame(1, Notification::where('delivery_key', $intent->id)->count());

        $this->signedInAs($recipient);
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.meta.route', "/tasks/boards/{$this->board->id}/tasks/{$this->task->id}");

        $this->enterTenant();
        Assignment::where('user_id', $recipient->id)->delete();
        $this->signedInAs($recipient->fresh());
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.unread', 0);
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 0);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }
}
