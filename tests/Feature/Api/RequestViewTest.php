<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class RequestViewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private Company $other;

    private Franchise $otherBranch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant();
        app(TenantContext::class)->run($this->tenant['org'], function () {
            $this->other = Company::create(['name' => 'Other', 'is_active' => true]);
            $this->otherBranch = Franchise::create(['company_id' => $this->other->id, 'name' => 'Other branch', 'is_active' => true]);
            $this->customer = Customer::create(['owning_franchise_id' => $this->tenant['franchise']->id, 'number' => 'C1', 'name' => 'Customer']);
            foreach ([$this->tenant['franchise'], $this->otherBranch] as $i => $franchise) {
                Job::create(['franchise_id' => $franchise->id, 'customer_id' => $this->customer->id,
                    'number' => 'JOB-'.$i, 'title' => 'Work', 'status' => 'scheduled', 'currency' => 'USD']);
            }
            $this->tenant['admin']->forceFill(['preferences' => ['navigation.scope' => 'company:'.$this->other->id]])->save();
        });
    }

    public function test_request_view_wins_over_another_devices_saved_preference(): void
    {
        $this->signedInAs($this->tenant['admin'])
            ->withHeader('X-EzBizi-View', 'company:'.$this->tenant['company']->id)
            ->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.scope.id', $this->tenant['company']->id);
        $this->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'JOB-0');
    }

    public function test_legacy_requests_still_follow_saved_preference(): void
    {
        $this->signedInAs($this->tenant['admin'])->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.scope.id', $this->other->id);
    }

    public function test_explicit_inaccessible_and_malformed_views_fail_closed(): void
    {
        $limited = $this->userWithRole($this->tenant, 'Viewer', ['jobs.view'], onlyAt: $this->tenant['franchise']);
        $this->signedInAs($limited);
        foreach (['company:'.$this->other->id, 'franchise:'.$this->otherBranch->id,
            'company:not-present', 'company:', 'unknown:123', 'organization:other', ''] as $value) {
            $this->withHeader('X-EzBizi-View', $value)->getJson('/api/v1/me')->assertForbidden();
        }
    }

    public function test_organization_header_does_not_expand_a_limited_users_reach(): void
    {
        $limited = $this->userWithRole($this->tenant, 'Viewer', ['jobs.view'], onlyAt: $this->tenant['franchise']);
        $this->signedInAs($limited)->withHeader('X-EzBizi-View', 'organization')
            ->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'JOB-0');
    }

    public function test_job_csv_bundle_and_person_export_follow_the_request_view(): void
    {
        $this->signedInAs($this->tenant['admin'])->withHeader('X-EzBizi-View', 'company:'.$this->tenant['company']->id);
        $csv = $this->get('/api/v1/exports/jobs')->assertOk()->streamedContent();
        $this->assertStringContainsString('JOB-0', $csv);
        $this->assertStringNotContainsString('JOB-1', $csv);
        $this->getJson('/api/v1/exports/bundle')->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.number', 'JOB-0');
        $this->getJson('/api/v1/customers/'.$this->customer->id.'/export')->assertOk()
            ->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.number', 'JOB-0');
    }
}
