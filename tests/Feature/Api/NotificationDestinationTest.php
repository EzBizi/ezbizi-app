<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\NotificationsController;
use App\Models\AutomationEvent;
use App\Models\Notification;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClassConstant;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Bar, 2026-10-12 (via Jose): tapping a notification must open "the exact
 * thing" it is about — "a new email notification should open that email".
 *
 * One case per kind measured on production that did NOT: an email pointed at
 * its mailbox, a missed call at nothing, a system notification at no module.
 */
class NotificationDestinationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function metaFor(array $tenant, array $row, ?array $event = null): array
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $row, $event): void {
            $eventId = $event === null ? null : AutomationEvent::create([
                'organization_id' => $tenant['org']->id, 'event_key' => $event['key'],
                'payload' => $event['payload'], 'occurred_at' => now(), 'available_at' => now(),
            ])->id;

            Notification::forceCreate([
                'id' => (string) Str::uuid7(), 'organization_id' => $tenant['org']->id,
                'user_id' => $tenant['admin']->id, 'source' => $row['source'] ?? 'automation',
                'title' => 'T', 'subject_type' => $row['subject_type'] ?? null,
                'subject_id' => $row['subject_id'] ?? null, 'event_id' => $eventId, 'created_at' => now(),
            ]);
        });

        return $this->signedInAs($tenant['admin'])->getJson('/api/v1/notifications')
            ->assertOk()->json('data.0.meta');
    }

    public function test_a_new_email_opens_that_message_not_its_mailbox(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $meta = $this->metaFor($tenant,
            ['subject_type' => \App\Models\Mailbox::class, 'subject_id' => (string) Str::uuid7()],
            ['key' => 'email.received', 'payload' => ['message_id' => 'msg-123', 'address' => 'info@alpha.test']],
        );

        $this->assertSame('email', $meta['module']);
        $this->assertSame(['type' => 'email_message', 'id' => 'msg-123', 'mailbox' => 'info@alpha.test'], $meta['record']);
    }

    public function test_an_assignment_is_kind_assigned_and_anything_else_is_not(): void
    {
        $tenant = $this->provisionTenant('Gamma');
        $this->metaFor($tenant, [], ['key' => 'job.assigned', 'payload' => []]);
        $this->assertSame('assigned', $this->signedInAs($tenant['admin'])->getJson('/api/v1/notifications')->json('data.0.kind'));

        $other = $this->provisionTenant('Delta');
        $this->metaFor($other, [], ['key' => 'vehicle.unassigned', 'payload' => []]);
        $this->assertNull($this->signedInAs($other['admin'])->getJson('/api/v1/notifications')->json('data.0.kind'));
    }

    public function test_a_missed_call_opens_that_call(): void
    {
        $tenant = $this->provisionTenant('Beta');
        $meta = $this->metaFor($tenant, [], ['key' => 'reports.call.missed', 'payload' => ['call_id' => 'rc-9']]);

        $this->assertSame('reports', $meta['module']);
        $this->assertSame(['type' => 'call', 'id' => 'rc-9'], $meta['record']);
    }

    public function test_a_system_notification_without_an_event_still_names_its_module_and_record(): void
    {
        $tenant = $this->provisionTenant('Gamma');
        $id = (string) Str::uuid7();
        $meta = $this->metaFor($tenant, [
            'source' => 'system',
            'subject_type' => \App\Models\TimeClockCorrectionRequest::class, 'subject_id' => $id,
        ]);

        // Before: module null, so a tap went nowhere.
        $this->assertSame('work_hours', $meta['module']);
        $this->assertSame(['type' => 'time_clock_correction_request', 'id' => $id], $meta['record']);
    }

    public function test_a_review_notification_opens_that_review(): void
    {
        $tenant = $this->provisionTenant('Delta');
        $id = (string) Str::uuid7();
        $meta = $this->metaFor($tenant,
            ['subject_type' => \App\Models\Review::class, 'subject_id' => $id],
            ['key' => 'review.removed', 'payload' => []],
        );

        $this->assertSame('reviews', $meta['module']);
        $this->assertSame(['type' => 'review', 'id' => $id], $meta['record']);
    }

    public function test_each_row_carries_the_same_header_as_its_push(): void
    {
        // Jose, 2026-10-22: the panel and the desktop toast lead with
        // "EzBizi - Company - Branch", like the push.
        $tenant = $this->provisionTenant('Epsilon');
        $this->metaFor($tenant, [
            'source' => 'system',
            'subject_type' => \App\Models\Franchise::class, 'subject_id' => $tenant['franchise']->id,
        ]);

        $this->signedInAs($tenant['admin'])->getJson('/api/v1/notifications')
            ->assertJsonPath('data.0.header', 'EzBizi - '.$tenant['company']->name.' - '.$tenant['franchise']->name);
    }

    public function test_a_task_mention_opens_its_task_at_that_update(): void
    {
        // Jose, 2026-11-17: a task notification leads to the board and task.
        $tenant = $this->provisionTenant('Zeta');
        [$taskId, $update] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $board = app(\App\Support\Tasks\TaskBoards::class)->ensureFor($tenant['admin']);
            $task = \App\Models\Task::query()->create([
                'board_id' => $board->id,
                'group_id' => \App\Models\TaskGroup::query()->where('board_id', $board->id)->value('id'),
                'franchise_id' => $board->franchise_id,
                'title' => 'Call back',
            ]);

            return [(string) $task->id, \App\Models\TaskUpdate::query()->forceCreate([
                'id' => (string) Str::uuid7(), 'organization_id' => $tenant['org']->id,
                'task_id' => $task->id, 'user_id' => $tenant['admin']->id, 'body' => '@you look',
            ])];
        });

        $meta = $this->metaFor($tenant, ['source' => 'system', 'subject_type' => \App\Models\TaskUpdate::class, 'subject_id' => $update->id]);

        $this->assertSame('tasks', $meta['module']);
        $this->assertSame(['type' => 'task', 'id' => $taskId, 'update' => $update->id], $meta['record']);
    }

    public function test_every_subject_module_is_a_real_module(): void
    {
        $map = (new ReflectionClassConstant(NotificationsController::class, 'SUBJECT_MODULES'))->getValue();
        $registry = app(ModuleRegistry::class);

        foreach ($map as $class => $module) {
            $this->assertTrue(class_exists($class), "{$class} is not a model");
            if ($module === 'suggestions') {
                continue; // the shell's pinned destination, not a module (main.dart _apply)
            }
            $this->assertNotNull($registry->get($module), "{$class} points at '{$module}', which is not a module");
        }
    }
}
