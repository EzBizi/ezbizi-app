<?php

namespace Tests\Feature\Workiz;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `ezbizi:workiz-customer-since`: the customers the projection made before it
 * wrote `customer_since`, given the day they first booked in Workiz -- and
 * nothing else touched (Customers, 2026-10-01).
 */
class WorkizCustomerSinceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Alpha');
    }

    /** A customer, with a Workiz key per entry of $jobs (client id => the booked moments of its jobs). */
    private function customer(array $jobs, ?string $since = null): Customer
    {
        return $this->inTenant($this->t['org'], function () use ($jobs, $since) {
            $franchise = $this->t['franchise']->id;
            $customer = Customer::create([
                'owning_franchise_id' => $franchise, 'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'Pat Rivera', 'is_active' => true, 'customer_since' => $since,
            ]);

            foreach ($jobs as $clientId => $booked) {
                WorkizClient::create(['franchise_id' => $franchise, 'client_id' => $clientId, 'customer_id' => $customer->id, 'synced_at' => now()]);

                foreach ($booked as $moment) {
                    WorkizJob::create([
                        'franchise_id' => $franchise, 'connection_id' => (string) Str::uuid(), 'remote_id' => Str::random(6),
                        'client_id' => $clientId, 'status' => 'Done', 'booked_at' => $moment, 'payload' => [], 'synced_at' => now(),
                    ]);
                }
            }

            return $customer;
        });
    }

    private function since(Customer $customer): ?string
    {
        return $this->inTenant($this->t['org'], fn () => $customer->fresh()->customer_since?->toDateString());
    }

    public function test_it_fills_the_first_booking_and_leaves_everything_else(): void
    {
        $projected = $this->customer([101 => ['2025-01-01 09:00:00', '2024-06-01 23:30:00']]);
        // One person with two Workiz client numbers: the earliest of both.
        $twoKeys = $this->customer([102 => ['2025-02-02 10:00:00'], 103 => ['2024-12-12 08:00:00']]);
        // The A1 import already said when: never overwritten.
        $imported = $this->customer([104 => ['2022-01-01 10:00:00']], since: '2023-05-05');
        // Typed into EzBizi: Workiz knows nothing about them.
        $typed = $this->customer([]);

        $this->artisan('ezbizi:workiz-customer-since', ['--dry-run' => true])
            ->assertSuccessful()->expectsOutputToContain('[dry run] 2 customers would get');
        $this->assertNull($this->since($projected), 'a dry run writes nothing');

        $this->artisan('ezbizi:workiz-customer-since')->assertSuccessful()->expectsOutputToContain('2 customers got');

        $this->assertSame('2024-06-01', $this->since($projected), "Workiz's own wall clock: the branch's day, not UTC's");
        $this->assertSame('2024-12-12', $this->since($twoKeys));
        $this->assertSame('2023-05-05', $this->since($imported));
        $this->assertNull($this->since($typed));

        $audit = $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'customer.since_backfilled')->sole());
        $this->assertSame(2, $audit->metadata['customers']);

        // Twice is once: the second run finds nothing left to fill.
        $this->artisan('ezbizi:workiz-customer-since')->assertSuccessful()->expectsOutputToContain('0 customers got');
    }
}
