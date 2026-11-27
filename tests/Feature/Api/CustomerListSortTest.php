<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /customers?sort=&direction= — the list ordered by the column clicked.
 *
 * Server-side because the list is paged. Sorting the fifty rows a screen holds
 * would sort page one and nothing else, and the "newest customer" would be the
 * newest of whichever fifty happened to be loaded.
 *
 * Every case builds rows whose ORDER differs by sort, and whose name order
 * disagrees with the order being asserted — otherwise a server that ignored the
 * parameter and sorted by name, as it always did, would pass.
 */
class CustomerListSortTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha', 'a@alpha.test');
    }

    private function customer(string $name, array $attributes = [], ?Franchise $franchise = null): Customer
    {
        return $this->inTenant($this->alpha['org'], fn () => Customer::create($attributes + [
            'owning_franchise_id' => ($franchise ?? $this->alpha['franchise'])->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => $name,
        ]));
    }

    private function names(string $query): array
    {
        return array_column(
            $this->signedInAs($this->alpha['admin'])
                ->getJson("/api/v1/customers?{$query}")->assertOk()->json('data'),
            'name',
        );
    }

    public function test_by_name_skips_leading_punctuation(): void
    {
        // Desktop review 2026-11-13: "'Zed" and "-- Bea" opened the list,
        // ahead of every A.
        foreach (["'Zed", 'Abe', '-- Bea'] as $name) {
            $this->customer($name);
        }

        $this->assertSame(['Abe', '-- Bea', "'Zed"], $this->names('sort=name&direction=asc'));
    }

    public function test_created_newest_first_is_not_the_alphabet(): void
    {
        // Dates that match the alphabet in NEITHER direction. The first draft
        // made them the exact reverse of the names, and it passed against a
        // server that ignored `sort`: descending by name is then the same list
        // as newest first, and the direction parameter alone produced it. The
        // answer here, Mia-Zed-Abe, is neither Abe-Mia-Zed nor Zed-Mia-Abe.
        foreach (['Mia' => '2026-09-01', 'Zed' => '2026-08-01', 'Abe' => '2026-07-01'] as $name => $day) {
            $customer = $this->customer($name);
            $this->inTenant($this->alpha['org'], fn () => Customer::query()
                ->whereKey($customer->id)
                ->update(['created_at' => Carbon::parse("{$day} 12:00:00", 'UTC')]));
        }

        $this->assertSame(['Mia', 'Zed', 'Abe'], $this->names('sort=created&direction=desc'));
        $this->assertSame(['Abe', 'Zed', 'Mia'], $this->names('sort=created&direction=asc'));
    }

    public function test_the_created_date_is_on_every_row(): void
    {
        $this->customer('Abe');

        $row = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/customers')->assertOk()->json('data.0');

        $this->assertNotNull($row['created_at']);
        $this->assertNotFalse(strtotime($row['created_at']));
    }

    public function test_jobs_sorts_by_how_many_not_by_name(): void
    {
        $many = $this->customer('Abe');
        $one = $this->customer('Mia');
        $this->customer('Zed');

        $this->inTenant($this->alpha['org'], function () use ($many, $one) {
            foreach ([$many, $many, $one] as $i => $customer) {
                Job::create([
                    'franchise_id' => $this->alpha['franchise']->id,
                    'customer_id' => $customer->id,
                    'number' => "J-{$i}",
                    'title' => "Job {$i}",
                    'status' => 'scheduled',
                ]);
            }
        });

        $this->assertSame(['Abe', 'Mia', 'Zed'], $this->names('sort=jobs&direction=desc'));
        $this->assertSame(['Zed', 'Mia', 'Abe'], $this->names('sort=jobs&direction=asc'));
    }

    public function test_owner_sorts_by_the_name_the_column_shows(): void
    {
        // Franchise NAMES in the opposite order to the customer names, so the
        // owner sort and the default name sort cannot coincide.
        $zulu = $this->extraFranchise($this->alpha, 'Zulu');
        $bravo = $this->extraFranchise($this->alpha, 'Bravo');

        $this->customer('Abe', franchise: $zulu);
        $this->customer('Zed', franchise: $bravo);

        $names = $this->names('sort=owner&direction=asc');

        $this->assertSame(['Zed', 'Abe'], $names);
    }

    public function test_a_column_that_is_not_on_screen_is_refused(): void
    {
        $this->customer('Abe');

        // The key reaches an ORDER BY. A free-text column name there would be
        // an injection point and a way to sort by something nobody can see.
        $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/customers?sort=password')
            ->assertStatus(422);
    }

    public function test_city_sorts_by_city_then_alphabetically_within_it(): void
    {
        // Two in Albany, one in Zurich, and Abe is the one in Zurich -- so the
        // answer (Mia, Zed, then Abe) is NOT the alphabet (Abe, Mia, Zed). A
        // first draft of this test put all three in one city, and it passed
        // against a server that ignored `sort` entirely, because "tied, then by
        // name" and "by name" are the same list when nothing differs.
        $this->customer('Zed', ['city' => 'Albany']);
        $this->customer('Abe', ['city' => 'Zurich']);
        $this->customer('Mia', ['city' => 'Albany']);

        $this->assertSame(['Mia', 'Zed', 'Abe'], $this->names('sort=city&direction=asc'));
    }
}
