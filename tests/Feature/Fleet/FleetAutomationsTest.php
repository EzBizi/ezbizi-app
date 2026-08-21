<?php

namespace Tests\Feature\Fleet;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleOwnershipPeriod;
use App\Support\Automations\ActionRunner;
use App\Support\Automations\AutomationEngine;
use App\Support\Fleet\VehicleStatus;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Fleet events in the outbox, and the actions a rule may take (spec §10).
 *
 * ModuleRegistryTest already greps app/ for quoted trigger keys. This watches
 * the outbox, because the same strings are easy to mention without emitting.
 */
class FleetAutomationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_creating_a_vehicle_writes_created_and_ownership_started(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/vehicles', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'year' => 2022,
                'make' => 'Ford',
                'model' => 'Transit',
                'plate' => 'ABC-1234',
                'body_type' => 'van',
                'fuel_type' => 'gas',
                'ownership' => [
                    'kind' => 'owned',
                    'counterparty' => 'Dealer Co',
                    'purchase_price_cents' => 2_500_000,
                    'loan_account' => 'SECRET-ACC-99',
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->inTenant($tenant['org'], function () use ($id) {
            $created = AutomationEvent::query()->where('event_key', 'vehicle.created')->first();
            $this->assertNotNull($created);
            $this->assertSame($id, $created->subject_id);
            $this->assertSame(Vehicle::class, $created->subject_type);
            $this->assertSame('active', $created->payload['after']['status'] ?? null);
            $this->assertSame('ABC-1234', $created->payload['after']['plate'] ?? null);
            $this->assertStringNotContainsString('SECRET-ACC-99', json_encode($created->payload));

            $started = AutomationEvent::query()->where('event_key', 'vehicle.ownership_started')->first();
            $this->assertNotNull($started);
            $this->assertSame($id, $started->subject_id);
            $this->assertSame('owned', $started->payload['after']['kind'] ?? null);
            $this->assertArrayNotHasKey('loan_account', $started->payload['after'] ?? []);
            $this->assertArrayNotHasKey('purchase_price_cents', $started->payload['after'] ?? []);
        });
    }

    public function test_identity_update_writes_updated_and_a_status_change_writes_old_and_new(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $owned = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'plate' => 'OLD-1',
                'status' => VehicleStatus::Active,
            ]);
            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
            ]);

            return $vehicle;
        });

        $this->signedInAs($admin)
            ->patchJson('/api/v1/vehicles/'.$owned->id, ['plate' => 'NEW-1'])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($owned) {
            $updated = AutomationEvent::query()->where('event_key', 'vehicle.updated')->first();
            $this->assertNotNull($updated);
            $this->assertSame($owned->id, $updated->subject_id);
            $this->assertSame('OLD-1', $updated->payload['before']['plate'] ?? null);
            $this->assertSame('NEW-1', $updated->payload['after']['plate'] ?? null);
            $this->assertFalse(
                AutomationEvent::query()->where('event_key', 'vehicle.status_changed')->exists(),
            );
        });

        $this->signedInAs($admin)
            ->patchJson('/api/v1/vehicles/'.$owned->id, ['status' => 'in_shop'])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($owned) {
            $changed = AutomationEvent::query()->where('event_key', 'vehicle.status_changed')->first();
            $this->assertNotNull($changed);
            $this->assertSame('active', $changed->payload['old'] ?? null);
            $this->assertSame('in_shop', $changed->payload['new'] ?? null);
            $this->assertSame(1, AutomationEvent::query()->where('event_key', 'vehicle.updated')->count());
        });

        $this->signedInAs($admin)
            ->patchJson('/api/v1/vehicles/'.$owned->id, [
                'colour' => 'white',
                'status' => 'out_of_service',
            ])
            ->assertOk();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(2, AutomationEvent::query()->where('event_key', 'vehicle.updated')->count());
            $this->assertSame(2, AutomationEvent::query()->where('event_key', 'vehicle.status_changed')->count());
        });
    }

    public function test_assign_while_keys_are_held_writes_unassigned_then_assigned(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $first = $this->userWithRole($tenant, 'FirstDriver', ['fleet_management.view']);
        $second = $this->userWithRole($tenant, 'SecondDriver', ['fleet_management.view']);

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant, $first) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            VehicleAssignment::factory()->create([
                'vehicle_id' => $vehicle->id,
                'user_id' => $first->id,
                'assigned_at' => now()->subDay(),
                'released_at' => null,
            ]);

            return $vehicle;
        });

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/assignments', [
                'user_id' => $second->id,
            ])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($vehicle, $first, $second) {
            $keys = AutomationEvent::query()
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->pluck('event_key')
                ->all();
            $this->assertSame(['vehicle.unassigned', 'vehicle.assigned'], $keys);

            $unassigned = AutomationEvent::query()->where('event_key', 'vehicle.unassigned')->first();
            $this->assertSame($vehicle->id, $unassigned->subject_id);
            $this->assertSame($first->id, $unassigned->payload['before']['user_id'] ?? null);

            $assigned = AutomationEvent::query()->where('event_key', 'vehicle.assigned')->first();
            $this->assertSame($second->id, $assigned->payload['after']['user_id'] ?? null);
        });

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/unassign')
            ->assertOk();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(2, AutomationEvent::query()->where('event_key', 'vehicle.unassigned')->count());
        });
    }

    public function test_ownership_insurance_service_and_payment_each_write_their_event(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'odometer_miles' => 10_000,
            ]);
            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
            ]);

            return $vehicle;
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/ownership', [
                'kind' => 'rented',
                'counterparty' => 'Rentals Inc',
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/insurance', [
                'carrier' => 'Mutual',
                'policy_number' => 'POL-1',
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/service', [
                'kind' => 'oil',
                'occurred_on' => now()->toDateString(),
                'odometer_miles' => 10_500,
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/payments', [
                'category' => 'maintenance',
                'amount_cents' => 12_500,
                'occurred_on' => now()->toDateString(),
                'method' => 'cash',
            ])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $keys = AutomationEvent::query()->pluck('event_key')->sort()->values()->all();
            $this->assertSame(
                [
                    'vehicle.insurance_started',
                    'vehicle.ownership_started',
                    'vehicle.payment_recorded',
                    'vehicle.service_logged',
                ],
                $keys,
            );

            foreach ($keys as $key) {
                $event = AutomationEvent::query()->where('event_key', $key)->first();
                $this->assertSame($vehicle->id, $event->subject_id, $key);
                $this->assertSame(Vehicle::class, $event->subject_type, $key);
            }

            $payment = AutomationEvent::query()->where('event_key', 'vehicle.payment_recorded')->first();
            $this->assertSame(12_500, $payment->payload['after']['amount_cents'] ?? null);

            $this->assertFalse(
                AutomationEvent::query()->where('event_key', 'vehicle.updated')->exists(),
                'A ledger write-through is not an identity update.',
            );
        });
    }

    public function test_buy_back_writes_status_changed_and_ownership_started(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'status' => VehicleStatus::Sold,
            ]);
            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
                'ended_on' => now()->subDay()->toDateString(),
                'ended_reason' => 'sold',
            ]);

            return $vehicle;
        });

        $this->signedInAs($tenant['admin'])
            ->patchJson('/api/v1/vehicles/'.$vehicle->id, [
                'status' => 'active',
                'ownership' => ['kind' => 'owned', 'counterparty' => 'Auction'],
            ])
            ->assertOk();

        $this->inTenant($tenant['org'], function () {
            $changed = AutomationEvent::query()->where('event_key', 'vehicle.status_changed')->first();
            $this->assertNotNull($changed);
            $this->assertSame('sold', $changed->payload['old'] ?? null);
            $this->assertSame('active', $changed->payload['new'] ?? null);

            $started = AutomationEvent::query()->where('event_key', 'vehicle.ownership_started')->first();
            $this->assertNotNull($started);
            $this->assertSame('owned', $started->payload['after']['kind'] ?? null);
            $this->assertSame('Auction', $started->payload['after']['counterparty'] ?? null);
        });
    }

    public function test_every_raised_fleet_trigger_is_emitted_and_the_clock_ones_are_not(): void
    {
        $module = app(ModuleRegistry::class)->get('fleet_management');
        $this->assertNotNull($module);

        $notRaised = array_values(array_filter(
            $module->triggers,
            fn (TriggerDefinition $t) => ! $t->isRaised,
        ));

        $this->assertSame(
            ['vehicle.insurance_expiring', 'vehicle.service_due'],
            array_map(fn (TriggerDefinition $t) => $t->key, $notRaised),
        );
        $this->assertStringContainsString(TriggerDefinition::NOT_RAISED_YET, $notRaised[0]->description);
        $this->assertStringContainsString(TriggerDefinition::NOT_RAISED_YET, $notRaised[1]->description);

        $tenant = $this->provisionTenant('Alpha');
        $this->walkTheWritePath($tenant);

        $emitted = $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->pluck('event_key')->unique()->values()->all());

        foreach ($module->triggers as $trigger) {
            if (! $trigger->isRaised) {
                $this->assertNotContains($trigger->key, $emitted, $trigger->key);
                continue;
            }

            $this->assertContains(
                $trigger->key,
                $emitted,
                "Fleet declares “{$trigger->label}” as raised, and a walk through the write "
                .'path never wrote it to the outbox.',
            );
        }
    }

    public function test_writable_vehicle_fields_are_non_money_and_exclude_status(): void
    {
        $writable = ActionRunner::writableOn(Vehicle::class);

        $this->assertSame(
            [
                'plate' => 'Plate',
                'colour' => 'Colour',
                'odometer_miles' => 'Odometer (miles)',
                'next_service_on' => 'Next service date',
                'next_service_miles' => 'Next service miles',
            ],
            $writable,
        );

        foreach (['purchase_price_cents', 'amount_cents', 'status', 'loan_account'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $writable);
        }
    }

    public function test_a_rule_cannot_mark_a_rental_sold(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $automation = Automation::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Mark it sold',
                'trigger_key' => 'vehicle.created',
                'is_active' => true,
            ]);
            $version = AutomationVersion::create([
                'organization_id' => $tenant['org']->id,
                'automation_id' => $automation->id,
                'version' => 1,
                'conditions' => null,
                'actions' => [['type' => 'change_status', 'value' => 'sold']],
                'created_at' => now(),
            ]);
            $automation->forceFill(['active_version_id' => $version->id])->save();
        });

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/vehicles', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'year' => 2020,
                'make' => 'Ford',
                'model' => 'Transit',
                'plate' => 'RENT-1',
                'body_type' => 'van',
                'fuel_type' => 'gas',
                'ownership' => ['kind' => 'rented', 'counterparty' => 'Lease Co'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->drain($tenant);

        $this->inTenant($tenant['org'], function () use ($id) {
            $vehicle = Vehicle::find($id);
            $this->assertSame(VehicleStatus::Active, $vehicle->status);
            $this->assertSame('rented', $vehicle->openOwnership()?->kind->value);

            $run = AutomationRun::query()->where('status', AutomationRun::FAILED)->first();
            $this->assertNotNull($run);
            $this->assertStringContainsString(
                'Sold is for a vehicle the company owns. Return a rental instead.',
                $run->action_results[0]['detail'] ?? '',
            );
        });
    }

    /** Every write that is supposed to raise an event, in one tenant. */
    private function walkTheWritePath(array $tenant): void
    {
        $admin = $tenant['admin'];
        $driver = $this->userWithRole($tenant, 'Walker', ['fleet_management.view']);

        $id = $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'year' => 2021,
                'make' => 'Ford',
                'model' => 'Transit',
                'plate' => 'WALK-1',
                'body_type' => 'van',
                'fuel_type' => 'gas',
                'ownership' => ['kind' => 'owned', 'counterparty' => 'Dealer'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($admin)
            ->patchJson('/api/v1/vehicles/'.$id, ['plate' => 'WALK-2'])
            ->assertOk();

        $this->signedInAs($admin)
            ->patchJson('/api/v1/vehicles/'.$id, ['status' => 'in_shop'])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$id.'/assignments', ['user_id' => $driver->id])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$id.'/unassign')
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$id.'/ownership', [
                'kind' => 'owned',
                'counterparty' => 'Second Dealer',
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$id.'/insurance', ['carrier' => 'Mutual'])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$id.'/service', [
                'kind' => 'oil',
                'occurred_on' => now()->toDateString(),
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$id.'/payments', [
                'category' => 'maintenance',
                'amount_cents' => 1000,
                'occurred_on' => now()->toDateString(),
                'method' => 'cash',
            ])
            ->assertOk();
    }

    private function drain(array $tenant): void
    {
        $engine = app(AutomationEngine::class);

        $this->inTenant($tenant['org'], function () use ($engine) {
            AutomationEvent::query()
                ->whereNull('delivered_at')
                ->orderBy('occurred_at')
                ->get()
                ->each(fn (AutomationEvent $e) => $engine->deliver($e));
        });
    }
}
