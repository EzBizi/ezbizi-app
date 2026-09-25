<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The customers list's City column and its Repeat / No jobs yet chips.
 *
 * Jose, 2026-09-24, choosing the new Customers screen: a table, divided by
 * letter. City was on 7,584 of 7,688 customers on the box and in NO list
 * payload — only the detailed one carried an address.
 *
 * The chips count the same way the Jobs chips do: each count leaves its own
 * filter out and keeps every other, so a search narrows all three at once.
 */
class CustomerListSegmentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_list_row_says_where_the_customer_is(): void
    {
        $this->threeCustomers();

        $row = collect($this->getJson('/api/v1/customers')->assertOk()->json('data'))
            ->firstWhere('name', 'Repeat Rita');

        $this->assertSame('Dallas', $row['city']);
        $this->assertSame('TX', $row['state']);
    }

    public function test_each_segment_is_its_own_list(): void
    {
        $this->threeCustomers();

        $this->assertSame(
            ['Repeat Rita'],
            $this->getJson('/api/v1/customers?segment=repeat')->assertOk()->json('data.*.name'),
        );

        $this->assertSame(
            ['Never Ned'],
            $this->getJson('/api/v1/customers?segment=none')->assertOk()->json('data.*.name'),
        );
    }

    public function test_the_counts_leave_the_segment_out_and_keep_the_search(): void
    {
        $this->threeCustomers();

        $this->assertSame(
            ['all' => 3, 'repeat' => 1, 'none' => 1],
            $this->getJson('/api/v1/customers?segment=repeat')->assertOk()->json('meta.segment_counts'),
        );

        // "r" is in Repeat Rita and in Never Ned, and nowhere in Once Otto —
        // and every count narrows with it.
        $this->assertSame(
            ['all' => 2, 'repeat' => 1, 'none' => 1],
            $this->getJson('/api/v1/customers?search=r')->assertOk()->json('meta.segment_counts'),
        );
    }

    public function test_later_pages_carry_no_counts(): void
    {
        $this->threeCustomers();

        $meta = $this->getJson('/api/v1/customers?per_page=1&page=2')->assertOk()->json('meta');

        $this->assertArrayNotHasKey('segment_counts', $meta);
    }

    public function test_an_unknown_segment_is_refused_not_ignored(): void
    {
        $this->threeCustomers();

        $this->getJson('/api/v1/customers?segment=vip')->assertUnprocessable();
    }

    /** Rita has two jobs, Otto one, Ned none. */
    private function threeCustomers(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->signedInAs($alpha['admin']);
        $franchise = $alpha['franchise']->id;

        $customer = fn (string $name, ?string $city = null, ?string $state = null) => $this->postJson('/api/v1/customers', [
            'owning_franchise_id' => $franchise,
            'name'                => $name,
            'city'                => $city,
            'state'               => $state,
        ])->assertCreated()->json('data.id');

        $job = fn (string $customerId, string $title) => $this->postJson('/api/v1/jobs', [
            'franchise_id' => $franchise,
            'customer_id'  => $customerId,
            'title'        => $title,
            'status'       => 'scheduled',
        ])->assertCreated();

        $rita = $customer('Repeat Rita', 'Dallas', 'TX');
        $otto = $customer('Once Otto');
        $customer('Never Ned');

        $job($rita, 'Sweep');
        $job($rita, 'Inspection');
        $job($otto, 'Sweep');
    }
}
