<?php

namespace Tests\Feature\Tasks;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnValue;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskDuplicationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $source;

    private Task $parent;

    private TaskColumnDefinition $textColumn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->lookAt(ViewScope::COMPANY, $this->alpha['company']->id);
        $this->actingAs($this->alpha['admin']->fresh());
        $this->source = $this->createBoard('Source');
        $this->textColumn = $this->createColumn($this->source, 'Reference', 'text');
        $collaborator = $this->userWithRole($this->alpha, 'Copy collaborator', ['tasks.view', 'tasks.edit']);
        $this->enterTenant();
        $status = $this->source->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $priority = $this->source->priorityOptions()->active()->where('rank', 'normal')->firstOrFail();
        $group = $this->source->groups()->active()->where('name', 'To do')->firstOrFail();
        $parentId = $this->postJson("/api/v1/tasks/boards/{$this->source->id}/tasks", [
            'title' => 'Parent to copy', 'description' => '<p>Safe brief</p>',
            'group_id' => $group->id, 'status_option_id' => $status->id,
            'priority_option_id' => $priority->id, 'owner_user_id' => $collaborator->id,
            'collaborator_user_ids' => [$collaborator->id], 'due_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->parent = Task::findOrFail($parentId);
        $this->postJson("/api/v1/tasks/boards/{$this->source->id}/tasks", [
            'title' => 'Child to copy', 'parent_task_id' => $this->parent->id,
            'status_option_id' => $status->id,
        ])->assertCreated();
        $this->putJson("/api/v1/tasks/{$this->parent->id}/columns/{$this->textColumn->id}", [
            'expected_version' => $this->parent->version, 'value' => 'REF-42',
        ])->assertOk();
        $this->enterTenant();
        $this->parent = $this->parent->fresh();
    }

    public function test_single_task_copy_preserves_tree_values_and_eligible_people_but_has_new_identity(): void
    {
        $group = $this->source->groups()->active()->where('name', 'Completed')->firstOrFail();
        $before = $this->source->tasks()->count();
        $response = $this->postJson("/api/v1/tasks/{$this->parent->id}/duplicate", [
            'expected_version' => $this->parent->version,
            'destination_board_id' => $this->source->id,
            'destination_group_id' => $group->id,
            'title' => 'Copied parent',
        ])->assertCreated()->assertJsonPath('data.title', 'Copied parent')
            ->assertJsonPath('data.group_id', $group->id);
        $this->enterTenant();
        $copy = Task::findOrFail($response->json('data.id'));
        $this->assertNotSame($this->parent->number, $copy->number);
        $this->assertSame($before + 2, $this->source->tasks()->count());
        $this->assertSame('Child to copy', $copy->subtasks()->firstOrFail()->title);
        $this->assertSame($this->parent->owner_user_id, $copy->owner_user_id);
        $this->assertSame($this->parent->collaborators()->pluck('user_id')->all(), $copy->collaborators()->pluck('user_id')->all());
        $this->assertSame('REF-42', TaskColumnValue::where('task_id', $copy->id)
            ->where('column_definition_id', $this->textColumn->id)->value('text_value'));

        $this->postJson("/api/v1/tasks/{$this->parent->id}/duplicate", [
            'expected_version' => $this->parent->version - 1,
            'destination_board_id' => $this->source->id,
            'destination_group_id' => $group->id,
        ])->assertConflict();
        $this->enterTenant();
        $this->assertSame($before + 2, $this->source->tasks()->count());
    }

    public function test_cross_board_copy_requires_complete_compatible_mappings_and_rolls_back_on_omission(): void
    {
        $destination = $this->createBoard('Destination');
        $destinationText = $this->createColumn($destination, 'Reference copy', 'text');
        $sourceStatus = $this->parent->statusOption;
        $sourcePriority = $this->parent->priorityOption;
        $destinationStatus = $destination->statusOptions()->active()->where('category', $sourceStatus->category)->firstOrFail();
        $destinationPriority = $destination->priorityOptions()->active()->where('rank', $sourcePriority->rank)->firstOrFail();
        $destinationGroup = $destination->groups()->active()->firstOrFail();
        $this->source->forceFill(['visibility' => 'private'])->save();
        $this->source = $this->source->fresh();

        $payload = [
            'expected_version' => $this->parent->version,
            'destination_board_id' => $destination->id,
            'destination_group_id' => $destinationGroup->id,
            'mappings' => [
                'status_options' => [$sourceStatus->id => $destinationStatus->id],
                'priority_options' => [$sourcePriority->id => $destinationPriority->id],
                'column_definitions' => [$this->textColumn->id => $destinationText->id],
            ],
        ];
        $copyId = $this->postJson("/api/v1/tasks/{$this->parent->id}/duplicate", $payload)
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->assertSame('REF-42', TaskColumnValue::where('task_id', $copyId)
            ->where('column_definition_id', $destinationText->id)->value('text_value'));
        $activity = TaskActivity::where('task_id', $copyId)->where('kind', 'task.duplicated')->firstOrFail();
        $this->assertArrayNotHasKey('source_board_id', $activity->data);
        $this->assertArrayNotHasKey('source_task_id', $activity->data);
        $audit = AuditEvent::where('target_id', $copyId)->where('action', 'task.duplicated')->firstOrFail();
        $customValue = collect($audit->changes['after']['custom_values'])
            ->firstWhere('column_definition_id', $destinationText->id);
        $this->assertSame('REF-42', $customValue['value']);

        unset($payload['mappings']['column_definitions']);
        $before = $destination->tasks()->count();
        $this->postJson("/api/v1/tasks/{$this->parent->id}/duplicate", $payload)->assertUnprocessable();
        $this->enterTenant();
        $this->assertSame($before, $destination->tasks()->count());
    }

    public function test_file_value_on_subtask_blocks_single_and_board_duplication_without_artifacts(): void
    {
        $file = $this->createColumn($this->source, 'Evidence', 'file');
        $child = $this->parent->subtasks()->firstOrFail();
        $attachmentId = (string) Str::uuid7();
        $now = now();
        DB::table('attachments')->insert([
            'id' => $attachmentId,
            'organization_id' => $this->alpha['org']->id,
            'attachable_type' => $child->getMorphClass(),
            'attachable_id' => $child->id,
            'disk' => 'local',
            'path' => 'tests/task-duplication.txt',
            'original_name' => 'task-duplication.txt',
            'mime' => 'text/plain',
            'size_bytes' => 4,
            'checksum' => str_repeat('a', 64),
            'uploaded_by_user_id' => $this->alpha['admin']->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('task_column_files')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->alpha['org']->id,
            'board_id' => $this->source->id,
            'task_id' => $child->id,
            'column_definition_id' => $file->id,
            'attachment_id' => $attachmentId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $taskCount = $this->source->tasks()->count();
        $group = $this->source->groups()->active()->firstOrFail();
        $this->postJson("/api/v1/tasks/{$this->parent->id}/duplicate", [
            'expected_version' => $this->parent->version,
            'destination_board_id' => $this->source->id,
            'destination_group_id' => $group->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('include_tasks');
        $this->enterTenant();
        $this->assertSame($taskCount, $this->source->tasks()->count());

        $boardCount = TaskBoard::count();
        $this->postJson("/api/v1/tasks/boards/{$this->source->id}/duplicate", [
            'name' => 'Must roll back',
            'include_tasks' => true,
            'expected_structure_version' => $this->source->fresh()->structure_version,
        ])->assertUnprocessable()->assertJsonValidationErrors('include_tasks');
        $this->enterTenant();
        $this->assertSame($boardCount, TaskBoard::count());
        $this->assertFalse(TaskBoard::where('name', 'Must roll back')->exists());
    }

    public function test_cross_board_preflight_reports_every_missing_mapping_before_writes(): void
    {
        $childColumn = $this->createColumn($this->source, 'Child reference', 'text');
        $child = $this->parent->subtasks()->firstOrFail();
        $this->putJson("/api/v1/tasks/{$child->id}/columns/{$childColumn->id}", [
            'expected_version' => $child->version,
            'value' => 'CHILD-1',
        ])->assertOk();
        $destination = $this->createBoard('Mapping destination');
        $destinationGroup = $destination->groups()->active()->firstOrFail();
        $before = $destination->tasks()->count();

        $response = $this->postJson("/api/v1/tasks/{$this->parent->id}/duplicate", [
            'expected_version' => $this->parent->version,
            'destination_board_id' => $destination->id,
            'destination_group_id' => $destinationGroup->id,
            'mappings' => [],
        ])->assertUnprocessable();
        $errors = $response->json('errors');
        $this->assertArrayHasKey('mappings.status_options.'.$this->parent->status_option_id, $errors);
        $this->assertArrayHasKey('mappings.priority_options.'.$this->parent->priority_option_id, $errors);
        $this->assertArrayHasKey('mappings.column_definitions.'.$this->textColumn->id, $errors);
        $this->assertArrayHasKey('mappings.column_definitions.'.$childColumn->id, $errors);
        $this->enterTenant();
        $this->assertSame($before, $destination->tasks()->count());
    }

    public function test_task_inclusive_board_copy_maps_configuration_and_work_in_one_transaction(): void
    {
        $response = $this->postJson("/api/v1/tasks/boards/{$this->source->id}/duplicate", [
            'name' => 'Full board copy',
            'include_tasks' => true,
            'expected_structure_version' => $this->source->structure_version,
        ])->assertCreated();
        $this->enterTenant();
        $copy = TaskBoard::findOrFail($response->json('data.id'));
        $this->assertSame(2, $copy->tasks()->count());
        $copiedParent = $copy->tasks()->whereNull('parent_task_id')->firstOrFail();
        $copiedDefinition = $copy->columnDefinitions()->where('name', 'Reference')->firstOrFail();
        $this->assertSame('REF-42', TaskColumnValue::where('task_id', $copiedParent->id)
            ->where('column_definition_id', $copiedDefinition->id)->value('text_value'));
        $this->assertSame(1, $copy->members()->count());
    }

    public function test_cross_place_board_copy_authorizes_source_and_current_destination_independently(): void
    {
        $this->enterTenant();
        $destinationCompany = Company::create(['name' => 'Sibling destination', 'is_active' => true]);
        $destinationFranchise = Franchise::create([
            'company_id' => $destinationCompany->id,
            'name' => 'Sibling destination branch',
            'is_active' => true,
        ]);
        $this->lookAt(ViewScope::COMPANY, $destinationCompany->id);
        $this->actingAs($this->alpha['admin']->fresh());
        $this->app->forgetScopedInstances();

        $copyId = $this->postJson("/api/v1/tasks/boards/{$this->source->id}/duplicate", [
            'name' => 'Cross-place structure copy',
            'expected_structure_version' => $this->source->structure_version,
        ])->assertCreated()
            ->assertJsonPath('data.company_id', $destinationCompany->id)
            ->assertJsonPath('data.franchise_id', null)
            ->json('data.id');
        $this->enterTenant();
        $copy = TaskBoard::findOrFail($copyId);
        $this->assertSame(0, $copy->tasks()->count());
        $this->assertSame($this->source->groups()->count(), $copy->groups()->count());

        $before = TaskBoard::count();
        $this->postJson("/api/v1/tasks/boards/{$this->source->id}/duplicate", [
            'name' => 'Cross-place work copy',
            'include_tasks' => true,
            'expected_structure_version' => $this->source->structure_version,
        ])->assertUnprocessable()->assertJsonValidationErrors('include_tasks');
        $this->enterTenant();
        $this->assertSame($before, TaskBoard::count());

        $sibling = $this->userWithRole(
            $this->alpha,
            'Sibling-only board manager',
            ['tasks.view', 'tasks.manage'],
            onlyAt: $destinationFranchise,
        );
        $this->enterTenant();
        $sibling->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::FRANCHISE.':'.$destinationFranchise->id,
        ]])->save();
        $this->signedInAs($sibling);
        $this->postJson("/api/v1/tasks/boards/{$this->source->id}/duplicate", [
            'name' => 'Forbidden sibling copy',
            'expected_structure_version' => $this->source->structure_version,
        ])->assertNotFound();
    }

    private function createBoard(string $name): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', ['name' => $name, 'visibility' => 'scope'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();

        return TaskBoard::findOrFail($id);
    }

    private function createColumn(TaskBoard $board, string $name, string $type): TaskColumnDefinition
    {
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns", [
            'name' => $name, 'type' => $type,
            'expected_structure_version' => $board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        if (isset($this->source) && $board->id === $this->source->id) {
            $this->source = $this->source->fresh();
        }

        return TaskColumnDefinition::findOrFail($id);
    }

    private function lookAt(string $type, string $id): void
    {
        $this->alpha['admin']->forceFill(['preferences' => ['navigation.scope' => $type.':'.$id]])->save();
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
