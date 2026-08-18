<?php

namespace Tests\Feature\Inspection;

use App\Models\Customer;
use App\Support\Inspection\ScopeKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InspectionTablesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_tables_exist(): void
    {
        foreach ([
            'inspection_definitions',
            'inspection_definition_versions',
            'inspection_runs',
            'inspection_reports',
            'inspection_definition_grants',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_two_customer_only_drafts_for_the_same_person_are_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $ids = $this->seedDefinition($tenant);

        $row = $this->runRow($tenant, $ids, jobId: null);

        DB::table('inspection_runs')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('inspection_runs')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
        ]);
    }

    public function test_a_second_person_may_draft_the_same_customer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $ids = $this->seedDefinition($tenant);
        $other = $this->userWithRole($tenant, 'Other', ['customers.view']);

        $first = $this->runRow($tenant, $ids, jobId: null);
        DB::table('inspection_runs')->insert($first);

        DB::table('inspection_runs')->insert([
            ...$first,
            'id' => (string) Str::uuid(),
            'user_id' => $other->id,
        ]);

        $this->assertSame(2, DB::table('inspection_runs')->count());
    }

    public function test_two_definitions_in_one_organization_cannot_share_a_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $row = [
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'name' => 'Untitled inspection',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('inspection_definitions')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('inspection_definitions')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
        ]);
    }

    /** @param  array{org: \App\Models\Organization, admin: \App\Models\User, franchise: \App\Models\Franchise}  $tenant */
    private function seedDefinition(array $tenant): array
    {
        $customer = $this->inTenant($tenant['org'], fn () => Customer::create([
            'owning_franchise_id' => $tenant['franchise']->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => '14 Maple',
        ]));

        $definitionId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('inspection_definitions')->insert([
            'id' => $definitionId,
            'organization_id' => $tenant['org']->id,
            'name' => 'Level 1',
            'created_by' => $tenant['admin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inspection_definition_versions')->insert([
            'id' => $versionId,
            'organization_id' => $tenant['org']->id,
            'inspection_definition_id' => $definitionId,
            'version' => 1,
            'layout_preset' => 'step_strip',
            'screens' => json_encode([]),
            'created_at' => now(),
        ]);

        return [
            'customer_id' => $customer->id,
            'definition_id' => $definitionId,
            'version_id' => $versionId,
        ];
    }

    /** @param  array{org: \App\Models\Organization, admin: \App\Models\User}  $tenant */
    private function runRow(array $tenant, array $ids, ?string $jobId): array
    {
        $now = now();

        return [
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'user_id' => $tenant['admin']->id,
            'customer_id' => $ids['customer_id'],
            'job_id' => $jobId,
            'inspection_definition_id' => $ids['definition_id'],
            'inspection_definition_version_id' => $ids['version_id'],
            'scope_key' => ScopeKey::for($ids['customer_id'], $jobId),
            'answers' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
