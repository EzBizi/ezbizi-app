<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Estimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Estimates are money: `estimates.*` is not enough without the Jobs Financials
 * toggle (Jose, 2026-09-29: money is "a financials toggle"), and the toggle is
 * not enough without `estimates.*`. Reach still applies.
 */
class EstimatePermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_estimate_rights_without_financials_see_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->anEstimate($alpha);
        $clerk = $this->userWithRole($alpha, 'Clerk', ['jobs.view', 'estimates.view']);

        $this->signedInAs($clerk)->getJson("/api/v1/estimates/{$id}")
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');
        $this->signedInAs($clerk)->getJson('/api/v1/estimates')
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');
    }

    /** The roles editor says it too (the desk's audit): an estimates tick without Financials is refused, not saved. */
    public function test_a_role_cannot_be_given_estimates_without_financials(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Estimator', 'permissions' => ['jobs.view', 'estimates.edit'],
        ])->assertStatus(422)->assertJsonPath(
            'errors.permissions.0',
            fn (string $m) => str_contains($m, 'estimates.edit needs jobs.financials'),
        );

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Estimator', 'permissions' => ['jobs.view', 'jobs.financials', 'estimates.edit'],
        ])->assertCreated();
    }

    public function test_estimate_rights_with_financials_see_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->anEstimate($alpha);
        $manager = $this->userWithRole($alpha, 'Manager', ['jobs.view', 'jobs.financials', 'estimates.view']);

        $this->signedInAs($manager)->getJson("/api/v1/estimates/{$id}")->assertOk()
            ->assertJsonPath('data.number', 'E-1');
    }

    public function test_financials_without_estimate_rights_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->anEstimate($alpha);
        $bookkeeper = $this->userWithRole($alpha, 'Bookkeeper', ['jobs.view', 'jobs.financials']);

        $response = $this->signedInAs($bookkeeper)->getJson("/api/v1/estimates/{$id}")->assertForbidden();
        $this->assertNotSame('Money on jobs is hidden for your role.', $response->json('message'),
            'Refused for the missing estimates right, not for money.');
    }

    public function test_reach_still_applies(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($alpha, 'Elsewhere');
        $id = $this->anEstimate($alpha);
        $branch = $this->userWithRole($alpha, 'Branch', ['jobs.view', 'jobs.financials', 'estimates.view'], onlyAt: $elsewhere);

        $this->signedInAs($branch)->getJson("/api/v1/estimates/{$id}")->assertForbidden();
    }

    private function anEstimate(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create(['owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-1', 'name' => 'A Customer']);

            return Estimate::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'E-1', 'status' => 'draft', 'currency' => 'USD'])->id;
        });
    }
}
