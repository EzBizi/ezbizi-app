<?php

namespace Tests\Feature\Tasks;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskNotificationIntent;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TasksApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->alpha['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->actingAs($this->alpha['admin']->fresh());
        $id = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($id);
    }

    public function test_create_sanitizes_description_validates_participants_and_writes_atomic_records(): void
    {
        $collaborator = $this->userWithRole($this->alpha, 'Collaborator', ['tasks.view', 'tasks.edit']);
        $this->enterTenant();
        $group = $this->group();
        $status = $this->taskStatus(StatusCategory::NotStarted);

        $response = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Inspect site',
            'description' => '<p style="color:red" onclick="bad()">Hello <strong>team</strong><script>alert(1)</script> <a href="http://bad.test">bad</a> <a href="https://safe.test">safe</a><img src="data:x"> </p>',
            'group_id' => $group->id,
            'status_option_id' => $status->id,
            'owner_user_id' => $collaborator->id,
            'collaborator_user_ids' => [$collaborator->id],
            'due_on' => '2026-09-01',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Inspect site')
            ->assertJsonPath('data.owner_user_id', $collaborator->id)
            ->assertJsonPath('data.due_revision', 1)
            ->assertJsonPath('data.version', 1);

        $html = (string) $response->json('data.description');
        $this->assertStringContainsString('<strong>team</strong>', $html);
        $this->assertStringContainsString('https://safe.test', $html);
        $this->assertStringNotContainsString('http://bad.test', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('Hello team', (string) $response->json('data.description_plain'));

        $this->enterTenant();
        $task = Task::findOrFail($response->json('data.id'));
        $this->assertSame(1, TaskActivity::where('task_id', $task->id)->count());
        $this->assertSame(3, AutomationEvent::where('subject_id', $task->id)->count());
        $this->assertSame(2, TaskNotificationIntent::where('task_id', $task->id)->count());
        $this->assertSame(2, $group->fresh()->order_version);
        $this->assertDatabaseHas('audit_events', ['action' => 'task.created', 'target_id' => $task->id]);

        $task = $this->patchTask($task, ['collaborator_user_ids' => []]);
        $audit = AuditEvent::where('action', 'task.updated')->where('target_id', $task->id)->latest('occurred_at')->firstOrFail();
        $this->assertSame([$collaborator->id], $audit->changes['before']['collaborator_user_ids']);
        $this->assertSame([], $audit->changes['after']['collaborator_user_ids']);
        $activity = TaskActivity::where('task_id', $task->id)->where('kind', 'task.updated')->firstOrFail();
        $this->assertSame([$collaborator->id], $activity->data['changes']['collaborator_user_ids']['before']);
        $this->assertSame([], $activity->data['changes']['collaborator_user_ids']['after']);
    }

    public function test_due_revisions_and_stable_status_categories_drive_resolution_without_touching_subtasks(): void
    {
        $parent = $this->createTask('Parent', dueOn: '2026-09-01');
        $child = $this->createTask('Child', parentId: $parent->id);
        $completed = $this->taskStatus(StatusCategory::Completed);
        $active = $this->taskStatus(StatusCategory::Active);
        $cancelled = $this->taskStatus(StatusCategory::Cancelled);

        $parent = $this->patchTask($parent, ['due_on' => '2026-09-02']);
        $this->assertSame(2, $parent->due_revision);
        $parent = $this->patchTask($parent, ['due_on' => '2026-09-01']);
        $this->assertSame(3, $parent->due_revision);
        $parent = $this->patchTask($parent, ['owner_user_id' => $this->alpha['admin']->id]);
        $this->assertSame(3, $parent->due_revision);

        $parent = $this->patchTask($parent, ['status_option_id' => $completed->id]);
        $this->assertNotNull($parent->completed_at);
        $this->assertSame($this->alpha['admin']->id, $parent->completed_by);
        $this->assertSame($child->status_option_id, $child->fresh()->status_option_id);

        $parent = $this->patchTask($parent, ['status_option_id' => $active->id]);
        $this->assertNull($parent->completed_at);
        $this->enterTenant();
        $this->assertTrue(AutomationEvent::where('subject_id', $parent->id)
            ->where('event_key', 'task.reopened')->exists());

        $parent = $this->patchTask($parent, ['status_option_id' => $cancelled->id]);
        $this->assertNotNull($parent->cancelled_at);
        $this->assertNull($parent->completed_at);
    }

    public function test_one_level_subtasks_and_parent_archive_restore_are_consistent(): void
    {
        $parent = $this->createTask('Parent');
        $child = $this->createTask('Child', parentId: $parent->id);
        $historyChild = $this->createTask('Archived child title', parentId: $parent->id);
        $this->postJson("/api/v1/tasks/{$historyChild->id}/archive", [
            'expected_version' => $historyChild->version,
        ])->assertOk();
        $response = $this->patchJson("/api/v1/tasks/{$parent->id}", [
            'expected_version' => $parent->version, 'title' => 'Parent changed',
        ])->assertOk();
        $this->assertNotContains($historyChild->id, collect($response->json('data.subtasks'))->pluck('id')->all());
        $this->assertStringNotContainsString('Archived child title', $response->getContent());
        $this->enterTenant();
        $parent = $parent->fresh();

        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Too deep',
            'parent_task_id' => $child->id,
            'status_option_id' => $this->taskStatus(StatusCategory::NotStarted)->id,
        ])->assertUnprocessable();

        $this->postJson("/api/v1/tasks/{$parent->id}/archive", [
            'expected_version' => $parent->version,
        ])->assertOk()->assertJsonPath('data.archived', true);
        $this->enterTenant();
        $parent = $parent->fresh();
        $child = $child->fresh();
        $this->assertNotNull($child->archived_at);
        $this->assertSame($parent->id, $child->archived_with_parent_id);

        $this->postJson("/api/v1/tasks/{$child->id}/restore", [
            'expected_version' => $child->version,
        ])->assertUnprocessable();
        $this->postJson("/api/v1/tasks/{$parent->id}/restore", [
            'expected_version' => $parent->version,
        ])->assertOk()->assertJsonPath('data.archived', false);
        $this->enterTenant();
        $this->assertNull($child->fresh()->archived_at);
        $this->assertNull($child->fresh()->archived_with_parent_id);
    }

    /**
     * Whole-branch review finding 6: applyUpdate()'s start/due guard used
     * to throw straight out of bulk()'s execution loop, bypassing the
     * per-task error protocol the version/archive-state pre-checks above
     * already use — a bulk date edit across many tasks failed with a bare,
     * task-less field error, no indication which of the selected tasks
     * carried the inverted pair. `Clean task` proves the response names
     * ONLY the offending task, not every task in the selection; bulk()
     * stays atomic (the existing "atomically" test above already
     * establishes that as this method's contract) — the fix is correct
     * ATTRIBUTION of the one refusal, not a partial commit.
     */
    public function test_a_bulk_date_edit_reports_only_the_task_with_a_legacy_inverted_pair(): void
    {
        $legacy = $this->createTask('Legacy task');
        $this->enterTenant();
        // The API itself refuses to create an inverted pair — this
        // bypasses TaskWriter entirely to simulate the historical data the
        // finding is about, the same way a row written before the guard
        // existed would look today.
        Task::withoutGlobalScopes()->where('id', $legacy->id)
            ->update(['start_on' => '2026-09-20', 'due_on' => '2026-09-10']);
        $legacy = $legacy->fresh();
        $clean = $this->createTask('Clean task');

        $response = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", [
            'tasks' => [
                ['id' => $legacy->id, 'expected_version' => $legacy->version],
                ['id' => $clean->id, 'expected_version' => $clean->version],
            ],
            // Leaves the legacy row's already-inverted start_on untouched
            // and only edits due_on — exactly the "the new value looks
            // fine in isolation" shape the finding describes.
            'changes' => ['due_on' => '2026-09-01'],
        ])->assertUnprocessable();

        // errors.tasks is a plain-string sentence FIRST (coordinator
        // follow-up A) — ApiResponse.reason (client/lib/core/api_client.dart)
        // announces the first `errors` value verbatim, and a list of maps
        // there reads to a screen-reader as a serialized map, not a
        // sentence. The structured per-task attribution moved to
        // errors.task_errors instead: a flat list of {task_id, field,
        // message}, not an array keyed by task id — ValidationException::
        // withMessages() routes through a MessageBag, which flattens away
        // exactly that kind of key (verified directly against Laravel, not
        // assumed; see the comment in TaskWriter::bulk()).
        $sentence = $response->json('errors.tasks.0');
        $this->assertIsString($sentence, 'errors.tasks must announce a plain sentence, not a structured map.');
        $this->assertStringContainsString('start date cannot be after the due date', $sentence);
        $tasksErrors = collect($response->json('errors.task_errors'));
        $this->assertTrue(
            $tasksErrors->contains(fn ($entry) => $entry['task_id'] === $legacy->id),
            'The offending task must be named.'
        );
        $this->assertFalse(
            $tasksErrors->contains(fn ($entry) => $entry['task_id'] === $clean->id),
            'A task with no problem must not be reported as an error too.'
        );
        $this->assertCount(1, $tasksErrors, 'Only the one offending task should be reported.');
        $offending = $tasksErrors->firstOrFail();
        $this->assertSame('due_on', $offending['field']);
        $this->assertStringContainsString('start date cannot be after the due date', $offending['message']);

        $this->enterTenant();
        // Atomic: the whole bulk request is refused, so the clean task's
        // due_on must not have been silently applied either.
        $this->assertNull($clean->fresh()->due_on);
        $this->assertSame('2026-09-10', $legacy->fresh()->due_on->format('Y-m-d'));
    }

    public function test_bulk_refuses_every_change_when_one_target_is_stale_then_updates_each_task_atomically(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $staleFirstVersion = $first->version;
        $first = $this->patchTask($first, ['title' => 'First changed']);

        $payload = [
            'tasks' => [
                ['id' => $first->id, 'expected_version' => $staleFirstVersion],
                ['id' => $second->id, 'expected_version' => $second->version],
            ],
            'changes' => ['due_on' => '2026-10-10'],
        ];
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $payload)
            ->assertUnprocessable();
        $this->enterTenant();
        $this->assertNull($first->fresh()->due_on);
        $this->assertNull($second->fresh()->due_on);

        $payload['tasks'][0]['expected_version'] = $first->version;
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $payload)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->enterTenant();
        $this->assertSame('2026-10-10', $first->fresh()->due_on?->format('Y-m-d'));
        $this->assertSame('2026-10-10', $second->fresh()->due_on?->format('Y-m-d'));
        $causations = DB::table('audit_events')->whereIn('target_id', [$first->id, $second->id])
            ->where('action', 'task.updated')->whereNotNull('causation_id')->pluck('causation_id')->unique();
        $this->assertCount(1, $causations);

        $parent = $this->createTask('Bulk parent');
        $child = $this->createTask('Bulk child', parentId: $parent->id);
        $cascade = [
            'tasks' => [
                ['id' => $parent->id, 'expected_version' => $parent->version],
                ['id' => $child->id, 'expected_version' => $child->version],
            ],
            'changes' => ['archived' => true],
        ];
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $cascade)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->enterTenant();
        $parent = $parent->fresh();
        $child = $child->fresh();
        $this->assertNotNull($parent->archived_at);
        $this->assertSame($parent->id, $child->archived_with_parent_id);

        $cascade['tasks'][0]['expected_version'] = $parent->version;
        $cascade['tasks'][1]['expected_version'] = $child->version;
        $cascade['changes']['archived'] = false;
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $cascade)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->enterTenant();
        $this->assertNull($parent->fresh()->archived_at);
        $this->assertNull($child->fresh()->archived_at);
    }

    /**
     * `start_on` after `due_on` (design spec §8; task-12 brief, fix round 1).
     * `TaskWriter::applyUpdate` checks the MERGED state — existing value plus
     * incoming change — because a PATCH may carry only one of the two dates.
     * A lone `start_on` here lands after the task's EXISTING, untouched
     * `due_on`, which validating the request in isolation would miss.
     */
    public function test_start_on_after_an_existing_due_on_is_refused(): void
    {
        $task = $this->createTask('Install the unit', dueOn: '2026-09-10');

        $response = $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version,
            'start_on' => '2026-09-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_on');
        $this->assertStringContainsString(
            'start date cannot be after the due date',
            (string) $response->json('errors.start_on.0'),
        );

        $this->enterTenant();
        $fresh = $task->fresh();
        // Not a swap and not a silent write — the row is exactly as it was.
        $this->assertNull($fresh->start_on);
        $this->assertSame('2026-09-10', $fresh->due_on->format('Y-m-d'));
        $this->assertSame($task->version, $fresh->version);
    }

    /** Same rule, the other field: `due_on` before an existing `start_on`. */
    public function test_due_on_before_an_existing_start_on_is_refused(): void
    {
        $task = $this->createTask('Install the unit', startOn: '2026-09-10');

        $response = $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version,
            'due_on' => '2026-09-05',
        ])->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->assertStringContainsString(
            'start date cannot be after the due date',
            (string) $response->json('errors.due_on.0'),
        );

        $this->enterTenant();
        $fresh = $task->fresh();
        $this->assertSame('2026-09-10', $fresh->start_on->format('Y-m-d'));
        $this->assertNull($fresh->due_on);
        $this->assertSame($task->version, $fresh->version);
    }

    /** Both dates in one PATCH, wrong order — refused before either is saved. */
    public function test_setting_both_dates_at_once_in_an_invalid_order_is_refused(): void
    {
        $task = $this->createTask('Install the unit');

        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version,
            'start_on' => '2026-09-15',
            'due_on' => '2026-09-10',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_on');

        $this->enterTenant();
        $fresh = $task->fresh();
        $this->assertNull($fresh->start_on);
        $this->assertNull($fresh->due_on);
        $this->assertSame($task->version, $fresh->version);
    }

    /** Both dates in one PATCH, valid order — this is the whole-bar drag's write. */
    public function test_setting_both_dates_at_once_in_a_valid_order_succeeds(): void
    {
        $task = $this->createTask('Install the unit');

        $task = $this->patchTask($task, ['start_on' => '2026-09-05', 'due_on' => '2026-09-10']);

        $this->assertSame('2026-09-05', $task->start_on->format('Y-m-d'));
        $this->assertSame('2026-09-10', $task->due_on->format('Y-m-d'));
    }

    /**
     * Clearing either date must stay allowed — a task with only one date, or
     * neither, has nothing to compare and is always valid (it is also a
     * render state Timeline's marker case depends on).
     */
    public function test_clearing_either_date_is_allowed(): void
    {
        $task = $this->createTask('Install the unit', dueOn: '2026-09-10', startOn: '2026-09-05');

        $task = $this->patchTask($task, ['start_on' => null]);
        $this->assertNull($task->start_on);
        $this->assertSame('2026-09-10', $task->due_on->format('Y-m-d'));

        $task = $this->patchTask($task, ['due_on' => null]);
        $this->assertNull($task->due_on);
    }

    /**
     * Same rule at creation (task-12 brief, fix round 2) — `TaskWriter::create()`
     * reuses `Refusal::startAfterDue()` rather than a second sentence. There
     * is no existing row to merge against here, so this only ever fires
     * when both dates are present in the one request and inverted.
     */
    public function test_creating_with_start_on_after_due_on_is_refused(): void
    {
        $response = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Install the unit',
            'status_option_id' => $this->taskStatus(StatusCategory::NotStarted)->id,
            'group_id' => $this->group()->id,
            'start_on' => '2026-09-15',
            'due_on' => '2026-09-10',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_on');
        $this->assertStringContainsString(
            'start date cannot be after the due date',
            (string) $response->json('errors.start_on.0'),
        );

        $this->enterTenant();
        $this->assertSame(0, Task::where('title', 'Install the unit')->count());
    }

    public function test_creating_with_both_dates_in_a_valid_order_succeeds(): void
    {
        $task = $this->createTask('Install the unit', dueOn: '2026-09-10', startOn: '2026-09-05');

        $this->assertSame('2026-09-05', $task->start_on->format('Y-m-d'));
        $this->assertSame('2026-09-10', $task->due_on->format('Y-m-d'));
    }

    public function test_creating_with_only_one_date_succeeds(): void
    {
        $dueOnly = $this->createTask('Order the unit', dueOn: '2026-09-10');
        $this->assertNull($dueOnly->start_on);
        $this->assertSame('2026-09-10', $dueOnly->due_on->format('Y-m-d'));

        $startOnly = $this->createTask('Begin demolition', startOn: '2026-09-05');
        $this->assertSame('2026-09-05', $startOnly->start_on->format('Y-m-d'));
        $this->assertNull($startOnly->due_on);
    }

    private function createTask(string $title, ?string $parentId = null, ?string $dueOn = null, ?string $startOn = null): Task
    {
        $payload = [
            'title' => $title,
            'status_option_id' => $this->taskStatus(StatusCategory::NotStarted)->id,
        ];
        if ($parentId === null) {
            $payload['group_id'] = $this->group()->id;
        } else {
            $payload['parent_task_id'] = $parentId;
        }
        if ($dueOn !== null) {
            $payload['due_on'] = $dueOn;
        }
        if ($startOn !== null) {
            $payload['start_on'] = $startOn;
        }
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", $payload)
            ->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function patchTask(Task $task, array $changes): Task
    {
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version,
            ...$changes,
        ])->assertOk();
        $this->enterTenant();

        return $task->fresh();
    }

    private function group()
    {
        return $this->board->groups()->active()->where('name', 'To do')->firstOrFail();
    }

    private function taskStatus(StatusCategory $category)
    {
        return $this->board->statusOptions()->active()->where('category', $category)->firstOrFail();
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
