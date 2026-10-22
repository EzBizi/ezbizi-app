<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InventoryBalance;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Job;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryLocationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Stock leaves a location, posted on save (spec §8.4).
 *
 * Job XOR a note. The job must appear in the caller’s GET /jobs view.
 * Naming it is not a Jobs write.
 */
class InventoryIssueTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_issue_three_of_ten_to_a_note_then_eight_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->itemAndLocation($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'note' => 'sold over the counter',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertIsString($created['number'] ?? null);
        $this->assertStringStartsWith('IS-', $created['number']);
        $this->assertSame('sold over the counter', $created['note']);

        $this->assertOnHand($tenant, $item->id, $location->id, '7.000');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, InventoryIssue::query()->count());
            $this->assertSame(1, InventoryIssueLine::query()->count());
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'note' => 'sold over the counter',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 8],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is not enough at this location.');

        $this->assertOnHand($tenant, $item->id, $location->id, '7.000');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, InventoryIssue::query()->count());
            $this->assertSame(1, InventoryIssueLine::query()->count());
        });
    }

    public function test_issue_to_a_job_in_another_franchise_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->itemAndLocation($tenant);
        $ownJob = $this->jobOn($tenant, $tenant['franchise']);

        $otherFranchise = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'name' => 'Other shop',
                'is_active' => true,
                'timezone' => $tenant['franchise']->timezone,
                'currency' => $tenant['franchise']->currency,
            ]);
        });
        $hiddenJob = $this->jobOn($tenant, $otherFranchise);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.edit', 'jobs.view'],
            onlyAt: $tenant['franchise'],
        );

        $listed = $this->signedInAs($local)
            ->getJson('/api/v1/jobs')
            ->assertOk()
            ->json('data');

        $listedIds = collect($listed)->pluck('id')->all();
        $this->assertContains($ownJob->id, $listedIds);
        $this->assertNotContains($hiddenJob->id, $listedIds);

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'job_id' => $hiddenJob->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That job is not in this view.');

        $this->assertOnHand($tenant, $item->id, $location->id, '10.000');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryIssue::query()->count());
        });
    }

    public function test_issue_with_neither_job_nor_note_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->itemAndLocation($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Say which job this is for, or why it left.');

        $this->assertOnHand($tenant, $item->id, $location->id, '10.000');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryIssue::query()->count());
        });
    }

    public function test_issue_to_a_visible_job_does_not_need_jobs_edit_and_does_not_write_job_columns(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->itemAndLocation($tenant);
        $job = $this->jobOn($tenant, $tenant['franchise'], [
            'title' => 'Crown inspection',
            'amount_minor' => 1999,
        ]);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $before = $this->inTenant($tenant['org'], function () use ($job) {
            $row = Job::query()->whereKey($job->id)->firstOrFail();

            return [
                'updated_at' => $row->updated_at?->toJSON(),
                'status' => $row->status,
                'amount_minor' => $row->amount_minor,
                'title' => $row->title,
            ];
        });

        $local = $this->userWithRole(
            $tenant,
            'Issuer',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.edit'],
            onlyAt: $tenant['franchise'],
        );

        $created = $this->signedInAs($local)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'job_id' => $job->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertStringStartsWith('IS-', $created['number']);
        $this->assertSame($job->id, $created['job']['id'] ?? null);
        $this->assertOnHand($tenant, $item->id, $location->id, '7.000');

        $this->inTenant($tenant['org'], function () use ($job, $before) {
            $row = Job::query()->whereKey($job->id)->firstOrFail();
            $this->assertSame($before['updated_at'], $row->updated_at?->toJSON());
            $this->assertSame($before['status'], $row->status);
            $this->assertSame($before['amount_minor'], $row->amount_minor);
            $this->assertSame($before['title'], $row->title);
        });
    }

    public function test_discontinued_item_cannot_go_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->itemAndLocation($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$item->id, [
                'status' => InventoryItemStatus::Discontinued->value,
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'note' => 'used at the shop',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is discontinued, so it cannot go out.');

        $this->assertOnHand($tenant, $item->id, $location->id, '10.000');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryIssue::query()->count());
        });
    }

    public function test_closed_location_cannot_issue(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->itemAndLocation($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->inTenant($tenant['org'], function () use ($location) {
            $location->status = InventoryLocationStatus::Closed;
            $location->save();
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'note' => 'used at the shop',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This location is closed.');

        $this->assertOnHand($tenant, $item->id, $location->id, '10.000');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryIssue::query()->count());
        });
    }

    public function test_refuses_an_item_the_caller_cannot_see(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $sibling = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);
        });

        [$ownItem, $foreignItem, $location] = $this->inTenant(
            $tenant['org'],
            function () use ($tenant, $sibling) {
                $location = InventoryLocation::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own shop',
                ]);
                $ownItem = InventoryItem::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own liner',
                ]);
                $foreignItem = InventoryItem::factory()->create([
                    'company_id' => $sibling->id,
                    'name' => 'Beta liner',
                ]);

                return [$ownItem, $foreignItem, $location];
            },
        );

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $foreignItem->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.edit'],
            onlyAt: $tenant['franchise'],
        );

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'note' => 'used at the shop',
                'lines' => [
                    ['item_id' => $foreignItem->id, 'qty' => 4],
                ],
            ])
            ->assertForbidden();

        $this->assertOnHand($tenant, $foreignItem->id, $location->id, '10.000');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryIssue::query()->count());
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $ownItem->id,
                'counted_as' => 10,
                'reason' => 'own count',
            ])
            ->assertCreated();

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'note' => 'used at the shop',
                'lines' => [
                    ['item_id' => $ownItem->id, 'qty' => 4],
                ],
            ])
            ->assertCreated();
    }

    public function test_index_lists_visible_issues(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->itemAndLocation($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id,
                'note' => 'sold over the counter',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $listed = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/issues')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($listed);
        $this->assertSame($created['id'], $listed[0]['id']);
        $this->assertSame('sold over the counter', $listed[0]['note']);

        $viewer = $this->userWithRole($tenant, 'Viewer', ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view']);
        $this->signedInAs($viewer)
            ->getJson('/api/v1/inventory/issues')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created['id']);

        $outsider = $this->userWithRole($tenant, 'Outsider', ['customers.view']);
        $this->signedInAs($outsider)
            ->getJson('/api/v1/inventory/issues')
            ->assertForbidden();
    }

    /**
     * @param  array{org: \App\Models\Organization, company: Company, franchise: Franchise}  $tenant
     * @return array{0: InventoryItem, 1: InventoryLocation}
     */
    private function itemAndLocation(array $tenant): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => '6 mil liner',
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Main shop',
            ]);

            return [$item, $location];
        });
    }

    /**
     * @param  array{org: \App\Models\Organization}  $tenant
     * @param  array<string, mixed>  $attributes
     */
    private function jobOn(array $tenant, Franchise $franchise, array $attributes = []): Job
    {
        return $this->inTenant($tenant['org'], function () use ($franchise, $attributes) {
            $customer = Customer::create([
                'owning_franchise_id' => $franchise->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'Person',
            ]);

            return Job::create($attributes + [
                'franchise_id' => $franchise->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Work',
                'status' => 'scheduled',
                'currency' => 'USD',
            ]);
        });
    }

    /** @param  array{org: \App\Models\Organization}  $tenant */
    private function assertOnHand(array $tenant, string $itemId, string $locationId, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($itemId, $locationId, $qty) {
            $this->assertSame($qty, InventoryBalance::qtyOnHand($itemId, $locationId));
        });
    }

    /** The search bar: `q` narrows by note, number or job; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [$item, $location] = $this->itemAndLocation($tenant);

        $this->signedInAs($admin)->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $location->id, 'item_id' => $item->id, 'counted_as' => 10, 'reason' => 'opening count',
        ])->assertCreated();
        foreach (['sold over the counter', 'used on the Hendricks flue'] as $note) {
            $this->signedInAs($admin)->postJson('/api/v1/inventory/issues', [
                'location_id' => $location->id, 'note' => $note, 'lines' => [['item_id' => $item->id, 'qty' => 1]],
            ])->assertCreated();
        }

        $this->signedInAs($admin)->getJson('/api/v1/inventory/issues?q=hendricks')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.note', 'used on the Hendricks flue');
        $this->signedInAs($admin)->getJson('/api/v1/inventory/issues?q=')->assertOk()->assertJsonCount(2, 'data');
        $this->signedInAs($admin)->getJson('/api/v1/inventory/issues')->assertOk()->assertJsonCount(2, 'data');
    }
}
