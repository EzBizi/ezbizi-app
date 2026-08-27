<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskDueReminderOccurrence;
use App\Models\TaskNotificationIntent;
use App\Models\TaskOverdueOccurrence;
use App\Support\Preferences\PreferenceRegistry;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskOverdueTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_due_workers_are_timezone_aware_idempotent_and_create_content_free_intents(): void
    {
        Carbon::setTestNow('2026-08-27 12:00:00 America/New_York');
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
            'tasks.due_reminder_hours' => 24,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations', 'visibility' => 'private',
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($alpha['org']);
        $board = TaskBoard::findOrFail($boardId);
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $board->groups()->active()->firstOrFail();
        $overdue = $this->task($board, $group->id, $status->id, $alpha['admin']->id, 'Late', '2026-08-26');

        $this->artisan('ezbizi:dispatch-task-overdue')->assertSuccessful();
        $this->artisan('ezbizi:dispatch-task-overdue')->assertSuccessful();
        app(TenantContext::class)->set($alpha['org']);
        $this->assertSame(1, TaskOverdueOccurrence::where('task_id', $overdue->id)->count());
        $this->assertSame(1, AutomationEvent::where('event_key', 'task.overdue')->where('subject_id', $overdue->id)->count());
        $this->assertSame(1, TaskNotificationIntent::where('task_id', $overdue->id)->where('kind', 'task.overdue')->count());

        $reminder = $this->task($board, $group->id, $status->id, $alpha['admin']->id, 'Tomorrow', '2026-08-28');
        Carbon::setTestNow('2026-08-27 23:30:00 America/New_York');
        $this->artisan('ezbizi:dispatch-task-due-reminders')->assertSuccessful();
        app(TenantContext::class)->set($alpha['org']);
        $this->assertSame(0, TaskDueReminderOccurrence::where('task_id', $reminder->id)->count());

        Carbon::setTestNow('2026-08-28 01:01:00 America/New_York');
        $this->artisan('ezbizi:dispatch-task-due-reminders')->assertSuccessful();
        $this->artisan('ezbizi:dispatch-task-due-reminders')->assertSuccessful();
        app(TenantContext::class)->set($alpha['org']);
        $occurrence = TaskDueReminderOccurrence::where('task_id', $reminder->id)->firstOrFail();
        $this->assertSame(24, $occurrence->lead_hours);
        $this->assertSame(1, TaskNotificationIntent::where('source_occurrence_key', $occurrence->id)->count());
    }

    public function test_due_configuration_is_registered_at_the_correct_ownership_level(): void
    {
        $setting = app(SettingRegistry::class)->getOrFail('tasks.due_reminders_enabled');
        $this->assertSame(SettingDefinition::TYPE_BOOL, $setting->type);
        $this->assertSame([SettingDefinition::SCOPE_ORGANIZATION], $setting->allowedScopes);
        $this->assertTrue($setting->default);

        $preference = app(PreferenceRegistry::class)->all()['tasks.due_reminder_hours'];
        $this->assertSame('enum', $preference['type']);
        $this->assertSame(24, $preference['default']);
        $this->assertSame([1, 4, 24, 48, 72], $preference['options']);
    }

    private function task(TaskBoard $board, string $groupId, string $statusId, string $ownerId, string $title, string $dueOn): Task
    {
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'group_id' => $groupId, 'status_option_id' => $statusId,
            'owner_user_id' => $ownerId, 'due_on' => $dueOn,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set(auth()->user()->organization);

        return Task::findOrFail($id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
