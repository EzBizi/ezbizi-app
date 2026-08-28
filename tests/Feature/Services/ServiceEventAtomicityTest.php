<?php

namespace Tests\Feature\Services;

use App\Models\AutomationEvent;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The record and its outbox row commit together, or neither does.
 *
 * `ezbizi:dispatch-automation-events` drains the outbox every minute, so an
 * event written outside the record's transaction is not a tidiness problem: a
 * `service.deleted` that outlives a failed delete fires customer rules within
 * the minute against a service still sitting in the catalogue.
 */
class ServiceEventAtomicityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_delete_that_fails_publishes_no_deletion_event(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $id = $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Chimney sweep',
                'price_minor' => 18900,
            ])
            ->assertCreated()
            ->json('data.id');

        // Fail the delete itself, which is the case the ordering has to survive:
        // the controller has already committed to going ahead by this point.
        Service::deleting(fn () => throw new RuntimeException('the delete failed'));

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/services/'.$id)
            ->assertStatus(500);

        $this->inTenant($tenant['org'], function () use ($id) {
            $this->assertTrue(
                Service::query()->whereKey($id)->exists(),
                'the delete failed, so the service should still be in the catalogue',
            );
            $this->assertFalse(
                AutomationEvent::query()->where('event_key', 'service.deleted')->exists(),
                'a service that still exists must not have published its own deletion',
            );
        });
    }

    public function test_an_update_whose_event_fails_keeps_the_old_record(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $id = $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Chimney sweep',
                'price_minor' => 18900,
            ])
            ->assertCreated()
            ->json('data.id');

        // Registered after the create, so it is the update's event that fails.
        AutomationEvent::creating(fn () => throw new RuntimeException('the outbox write failed'));

        $this->signedInAs($admin)
            ->patchJson('/api/v1/services/'.$id, ['name' => 'Liner replacement'])
            ->assertStatus(500);

        $this->inTenant($tenant['org'], function () use ($id) {
            $this->assertSame(
                'Chimney sweep',
                Service::query()->whereKey($id)->value('name'),
                'no event was recorded, so the rename it described must not have stuck',
            );
        });
    }
}
