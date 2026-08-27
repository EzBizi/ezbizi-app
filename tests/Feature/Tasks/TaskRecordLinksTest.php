<?php

namespace Tests\Feature\Tasks;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

final class TaskRecordLinksTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
        $this->signedInAs($this->tenant['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', ['name' => 'Operations', 'visibility' => 'scope'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $board = TaskBoard::findOrFail($boardId);
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => 'Prepare visit', 'group_id' => $board->groups()->active()->firstOrFail()->id,
            'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->task = Task::findOrFail($taskId);
    }

    public function test_registry_search_link_and_unlink_are_scope_governed_and_versioned(): void
    {
        $this->enterTenant();
        $allowed = Customer::factory()->create([
            'owning_franchise_id' => $this->tenant['franchise']->id,
            'number' => 'C-100', 'name' => 'Allowed Customer',
        ]);
        $otherCompany = Company::create(['name' => 'Sibling', 'is_active' => true]);
        $otherFranchise = Franchise::create([
            'company_id' => $otherCompany->id, 'name' => 'Sibling Branch', 'is_active' => true,
        ]);
        Customer::factory()->create([
            'owning_franchise_id' => $otherFranchise->id,
            'number' => 'C-200', 'name' => 'Hidden Customer',
        ]);

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->getJson("/api/v1/tasks/{$this->task->id}/record-links/types")
            ->assertOk()->assertJsonCount(8, 'data');
        $this->getJson("/api/v1/tasks/{$this->task->id}/record-links/search?type=customer&q=Customer")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->id);

        $created = $this->postJson("/api/v1/tasks/{$this->task->id}/record-links", [
            'expected_task_version' => $this->task->fresh()->version,
            'record_type' => 'customer', 'record_id' => $allowed->id,
        ])->assertCreated()->assertJsonPath('data.available', true)
            ->assertJsonPath('data.label', 'Allowed Customer');
        $linkId = $created->json('data.link_id');

        $this->postJson("/api/v1/tasks/{$this->task->id}/record-links", [
            'expected_task_version' => 1, 'record_type' => 'customer', 'record_id' => $allowed->id,
        ])->assertConflict();
        $this->deleteJson("/api/v1/tasks/{$this->task->id}/record-links/{$linkId}", [
            'expected_task_version' => $this->task->fresh()->version,
        ])->assertOk()->assertJsonPath('data.removed', true);
    }

    public function test_detail_reauthorizes_links_and_hides_snapshot_and_target_identity_when_unavailable(): void
    {
        $this->enterTenant();
        $customer = Customer::factory()->create([
            'owning_franchise_id' => $this->tenant['franchise']->id,
            'number' => 'C-300', 'name' => 'Private Name',
        ]);
        $this->signedInAs($this->tenant['admin']->fresh());
        $this->postJson("/api/v1/tasks/{$this->task->id}/record-links", [
            'expected_task_version' => $this->task->fresh()->version,
            'record_type' => 'customer', 'record_id' => $customer->id,
        ])->assertCreated();

        $before = $this->getJson("/api/v1/tasks/boards/{$this->task->board_id}/tasks/{$this->task->id}")
            ->assertOk();
        $etag = $before->headers->get('ETag');

        $this->enterTenant();
        $customer->delete();
        $this->signedInAs($this->tenant['admin']->fresh());
        $data = $this->withHeader('If-None-Match', $etag)
            ->getJson("/api/v1/tasks/boards/{$this->task->board_id}/tasks/{$this->task->id}")
            ->assertOk()->json('data.record_links.0');
        $this->assertSame(['link_id', 'available', 'type'], array_keys($data));
        $this->assertFalse($data['available']);
        $this->assertSame('Customer', $data['type']);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }
}
