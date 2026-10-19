<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-19: "when im on jobs or customers but on organization level
 * there should be an extra column that shows the name of the company it
 * belongs to". Each job says its company, through its franchise, on the list
 * and in the export.
 */
class JobCompanyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_listed_job_says_its_company(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->aJob($alpha);

        $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs')->assertOk()
            ->assertJsonPath('data.0.company', ['id' => $alpha['company']->id, 'name' => $alpha['company']->name]);
    }

    public function test_the_export_carries_the_company_name(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->aJob($alpha);

        $rows = array_map('str_getcsv', array_filter(explode("\n",
            $this->signedInAs($alpha['admin'])->get('/api/v1/exports/jobs')->assertOk()->streamedContent())));
        $at = array_search('company', $rows[0], true);

        $this->assertNotFalse($at, 'the header names the column');
        $this->assertSame($alpha['company']->name, $rows[1][$at]);
    }

    private function aJob(array $alpha): void
    {
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer']);
            Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-1', 'title' => 'A job', 'status' => 'scheduled', 'currency' => 'USD']);
        });
    }
}
