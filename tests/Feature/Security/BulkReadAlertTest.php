<?php

namespace Tests\Feature\Security;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Bulk-read alert (DetectBulkReads, config/bulk_read.php) and the per-user
 * throttles on the customer, lead and job lists.
 */
class BulkReadAlertTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array, 1: string[]} */
    private function tenantWithCustomers(int $count): array
    {
        $tenant = $this->provisionTenant('Bulk');
        $ids = $this->inTenant($tenant['org'], fn () => Customer::factory()->count($count)
            ->create(['owning_franchise_id' => $tenant['franchise']->id])->pluck('id')->all());

        return [$tenant, $ids];
    }

    private function alertsFor(array $tenant, User $user): int
    {
        return $this->inTenant($tenant['org'], fn () => Notification::query()
            ->where('user_id', $user->id)->where('title', 'Unusual number of records read')->count());
    }

    private function auditRows(array $tenant): \Illuminate\Support\Collection
    {
        return $this->inTenant($tenant['org'], fn () => AuditEvent::query()->where('action', 'security.bulk_read')->get());
    }

    public function test_crossing_the_threshold_alerts_the_admins_once(): void
    {
        config(['bulk_read.thresholds.dispatcher' => ['records' => 3, 'pages' => 100]]);
        [$tenant, $ids] = $this->tenantWithCustomers(6);
        $reader = $this->userWithRole($tenant, 'Dispatcher', ['customers.view']);

        $this->signedInAs($reader);
        foreach ($ids as $id) {
            $this->getJson("/api/v1/customers/{$id}")->assertOk();
        }
        // Re-reading one already counted adds nothing and raises nothing.
        $this->getJson("/api/v1/customers/{$ids[0]}")->assertOk();

        $rows = $this->auditRows($tenant);
        $this->assertCount(1, $rows);
        $this->assertSame($reader->id, $rows[0]->actor_id);
        $this->assertSame('user', $rows[0]->metadata['actor']);
        $this->assertSame(4, $rows[0]->metadata['count']);
        $this->assertSame(3, $rows[0]->metadata['threshold']);
        $this->assertSame('dispatcher', $rows[0]->metadata['threshold_role']);

        $this->assertSame(1, $this->alertsFor($tenant, $tenant['admin']));
        // The reader is not an administrator and is not told.
        $this->assertSame(0, $this->alertsFor($tenant, $reader));
    }

    public function test_reading_up_to_the_threshold_raises_nothing(): void
    {
        config(['bulk_read.thresholds.dispatcher' => ['records' => 3, 'pages' => 100]]);
        [$tenant, $ids] = $this->tenantWithCustomers(3);
        $reader = $this->userWithRole($tenant, 'Dispatcher', ['customers.view']);

        $this->signedInAs($reader);
        foreach ($ids as $id) {
            $this->getJson("/api/v1/customers/{$id}")->assertOk();
        }

        $this->assertCount(0, $this->auditRows($tenant));
        $this->assertSame(0, $this->alertsFor($tenant, $tenant['admin']));
    }

    public function test_an_api_key_is_measured_as_the_key(): void
    {
        // People get a high bar, keys a low one: only the key path can trip it.
        config([
            'bulk_read.thresholds.default' => ['records' => 1000, 'pages' => 1000],
            'bulk_read.thresholds.administrator' => ['records' => 1000, 'pages' => 1000],
            'bulk_read.thresholds.api_key' => ['records' => 2, 'pages' => 1000],
        ]);
        [$tenant, $ids] = $this->tenantWithCustomers(3);

        $key = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/me/api-keys', ['name' => 'Agent', 'access' => 'read'])
            ->assertCreated()->json('key');
        $this->app['auth']->forgetGuards();

        foreach ($ids as $id) {
            $this->withToken($key)->getJson("/api/v1/customers/{$id}")->assertOk();
            $this->app['auth']->forgetGuards();
        }

        $rows = $this->auditRows($tenant);
        $this->assertCount(1, $rows);
        $this->assertSame('api_key', $rows[0]->metadata['actor']);
        $this->assertSame('api_key', $rows[0]->metadata['threshold_role']);
        $this->assertNotNull($rows[0]->metadata['token_id']);
        $this->assertSame(1, $this->alertsFor($tenant, $tenant['admin']));
    }

    public function test_paging_a_list_counts_distinct_pages(): void
    {
        config(['bulk_read.thresholds.dispatcher' => ['records' => 1000, 'pages' => 2]]);
        [$tenant] = $this->tenantWithCustomers(1);
        $reader = $this->userWithRole($tenant, 'Dispatcher', ['customers.view']);

        $this->signedInAs($reader);
        foreach ([1, 1, 2] as $page) {
            $this->getJson("/api/v1/customers?page={$page}")->assertOk();
        }
        $this->assertCount(0, $this->auditRows($tenant));

        $this->getJson('/api/v1/customers?page=3')->assertOk();
        $this->assertCount(1, $this->auditRows($tenant));
        $this->assertSame('pages', $this->auditRows($tenant)[0]->metadata['kind']);
    }

    public function test_the_customer_list_is_throttled_per_user(): void
    {
        [$tenant] = $this->tenantWithCustomers(1);
        $reader = $this->userWithRole($tenant, 'Dispatcher', ['customers.view']);

        $this->signedInAs($reader);
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/customers')->assertOk();
        }
        $this->getJson('/api/v1/customers')->assertStatus(429);
    }
}
