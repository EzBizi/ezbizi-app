<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-09-29: money in Jobs is "decided on roles y permissions as a
 * financials toggle". Without `jobs.financials` no amount reaches a person, and
 * none can be written. Money never travels with edit.
 */
class JobFinancialsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_without_the_toggle_no_amount_reaches_the_list_or_the_job(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aPricedJob($alpha);
        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view', 'jobs.edit']);

        $row = $this->signedInAs($dispatcher)->getJson('/api/v1/jobs')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('amount', $row);

        $detail = $this->signedInAs($dispatcher)->getJson("/api/v1/jobs/{$job}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('amount', $detail);
        $this->assertArrayNotHasKey('lines', $detail);
    }

    /** The control: the same person with the toggle sees it all. */
    public function test_the_toggle_shows_the_money(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aPricedJob($alpha);
        $manager = $this->userWithRole($alpha, 'Manager', ['jobs.view', 'jobs.edit', 'jobs.financials']);

        $this->signedInAs($manager)->getJson('/api/v1/jobs')->assertOk()
            ->assertJsonPath('data.0.amount.minor', 12345);
        $this->signedInAs($manager)->getJson("/api/v1/jobs/{$job}")->assertOk()
            ->assertJsonPath('data.amount.minor', 12345)
            ->assertJsonPath('data.lines', []);
    }

    public function test_without_the_toggle_money_cannot_be_written_but_edit_still_works(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aPricedJob($alpha);
        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view', 'jobs.edit']);

        $this->signedInAs($dispatcher)->patchJson("/api/v1/jobs/{$job}", ['amount_minor' => 1])
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');
        $this->signedInAs($dispatcher)->postJson("/api/v1/jobs/{$job}/lines", [])
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');

        $this->signedInAs($dispatcher)->patchJson("/api/v1/jobs/{$job}", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.title', 'Renamed');
    }

    private function aPricedJob(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'A Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'A job',
                'status' => 'scheduled',
                'amount_minor' => 12345,
                'currency' => 'USD',
            ])->id;
        });
    }
}
