<?php

namespace Tests\Feature\Reports;

use App\Models\SavedReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class SavedReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_saved_report_belongs_to_its_tenant_and_keeps_its_definition(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $report = $this->inTenant($tenant['org'], fn () => SavedReport::create([
            'company_id'   => $tenant['company']->id,
            'franchise_id' => null,
            'name'         => 'Open jobs',
            'description'  => 'Everything still open.',
            'definition'   => [
                'root'    => 'jobs',
                'columns' => [['resource' => 'jobs', 'column' => 'number']],
                'filters' => [],
            ],
        ]));

        $this->assertSame($tenant['org']->id, $report->organization_id);
        $this->assertSame('jobs', $report->definition['root']);
        $this->assertIsArray($report->definition['columns']);
        $this->assertSame('number', $report->definition['columns'][0]['column']);
    }

    public function test_the_definition_survives_a_round_trip_through_the_database(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->inTenant($tenant['org'], fn () => SavedReport::create([
            'company_id' => $tenant['company']->id,
            'name'       => 'Round trip',
            'definition' => [
                'root'    => 'jobs',
                'columns' => [
                    ['resource' => 'jobs', 'column' => 'title'],
                    ['resource' => 'customers', 'column' => 'name'],
                ],
                'filters' => [
                    ['resource' => 'jobs', 'column' => 'status', 'op' => 'eq', 'value' => 'open'],
                ],
            ],
        ])->id);

        // Read back fresh rather than trusting the in-memory cast — a JSON
        // column that serialises fine and deserialises wrong looks correct
        // right up until somebody reopens the report tomorrow.
        $read = $this->inTenant($tenant['org'], fn () => SavedReport::findOrFail($id));

        $this->assertCount(2, $read->definition['columns']);
        $this->assertSame('customers', $read->definition['columns'][1]['resource']);
        $this->assertSame('open', $read->definition['filters'][0]['value']);
    }

    public function test_two_reports_in_one_organization_cannot_share_a_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            SavedReport::create([
                'company_id' => $tenant['company']->id,
                'name'       => 'Open jobs',
                'definition' => ['root' => 'jobs', 'columns' => [], 'filters' => []],
            ]);

            $this->expectException(QueryException::class);

            SavedReport::create([
                'company_id' => $tenant['company']->id,
                'name'       => 'Open jobs',
                'definition' => ['root' => 'jobs', 'columns' => [], 'filters' => []],
            ]);
        });
    }

    /**
     * The positive half of the uniqueness rule. Without it, a constraint
     * mistakenly scoped to the name alone would pass the test above and
     * silently stop two organizations having a report called "Open jobs".
     */
    public function test_two_organizations_may_each_have_a_report_of_the_same_name(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->inTenant($alpha['org'], fn () => SavedReport::create([
            'company_id' => $alpha['company']->id,
            'name'       => 'Open jobs',
            'definition' => ['root' => 'jobs', 'columns' => [], 'filters' => []],
        ]));

        $second = $this->inTenant($beta['org'], fn () => SavedReport::create([
            'company_id' => $beta['company']->id,
            'name'       => 'Open jobs',
            'definition' => ['root' => 'jobs', 'columns' => [], 'filters' => []],
        ]));

        $this->assertSame($beta['org']->id, $second->organization_id);
    }

    public function test_another_organizations_report_is_not_visible(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->inTenant($beta['org'], fn () => SavedReport::create([
            'company_id' => $beta['company']->id,
            'name'       => 'Beta only',
            'definition' => ['root' => 'jobs', 'columns' => [], 'filters' => []],
        ]));

        $seen = $this->inTenant($alpha['org'], fn () => SavedReport::query()->pluck('name')->all());

        $this->assertSame([], $seen);
    }
}
