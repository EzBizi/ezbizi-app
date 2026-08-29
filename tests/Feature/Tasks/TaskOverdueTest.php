<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskDueReminderOccurrence;
use App\Models\TaskNotificationIntent;
use App\Models\TaskOverdueOccurrence;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Modules\ModuleRegistry;
use App\Support\Preferences\PreferenceRegistry;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
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

    public function test_one_failing_candidate_does_not_stop_the_rest_of_the_batch(): void
    {
        Carbon::setTestNow('2026-08-27 12:00:00 America/New_York');
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations', 'visibility' => 'private',
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($alpha['org']);
        $board = TaskBoard::findOrFail($boardId);
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $board->groups()->active()->firstOrFail();

        // Bad sorts before Good in eachCandidate()'s `orderBy('due_on')`, so
        // the bad candidate is guaranteed to be processed FIRST, with the
        // good one queued directly behind it in the same run.
        $bad = $this->task($board, $group->id, $status->id, $alpha['admin']->id, 'Bad', '2026-08-24');
        $good = $this->task($board, $group->id, $status->id, $alpha['admin']->id, 'Good', '2026-08-26');

        // Force the bad candidate to throw mid-transaction: EventEmitter is
        // called unconditionally inside observeOverdue()'s DB::transaction,
        // so intercepting it for this one task id is a precise, deterministic
        // way to simulate the kind of uncaught failure a real candidate could
        // hit (a bad automation rule, a serialization error, ...) without
        // touching TaskDueWorker itself.
        $this->app->bind(EventEmitter::class, function ($app) use ($bad) {
            return new class(
                $app->make(TenantContext::class),
                $app->make(ModuleRegistry::class),
                $app->make(AuditLogger::class),
                $bad->id,
            ) extends EventEmitter {
                public function __construct(
                    TenantContext $tenant,
                    ModuleRegistry $registry,
                    AuditLogger $audit,
                    private readonly string $badTaskId,
                ) {
                    parent::__construct($tenant, $registry, $audit);
                }

                public function emit(
                    string $eventKey,
                    ?Model $subject = null,
                    array $payload = [],
                    ?User $actor = null,
                    ?AutomationEvent $causedBy = null,
                    ?\DateTimeInterface $availableAt = null,
                ): AutomationEvent {
                    if ($eventKey === 'task.overdue' && $subject?->getKey() === $this->badTaskId) {
                        throw new \RuntimeException('Simulated failure for batch-isolation test.');
                    }

                    return parent::emit($eventKey, $subject, $payload, $actor, $causedBy, $availableAt);
                }
            };
        });

        $this->artisan('ezbizi:dispatch-task-overdue')->assertSuccessful();

        app(TenantContext::class)->set($alpha['org']);
        $this->assertSame(0, TaskOverdueOccurrence::where('task_id', $bad->id)->count(),
            'The failing candidate must not have partially recorded anything.');
        $this->assertSame(0, AutomationEvent::where('event_key', 'task.overdue')->where('subject_id', $bad->id)->count());
        $this->assertSame(1, TaskOverdueOccurrence::where('task_id', $good->id)->count(),
            'A candidate queued behind a failing one must still be processed.');
        $this->assertSame(1, AutomationEvent::where('event_key', 'task.overdue')->where('subject_id', $good->id)->count());
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
