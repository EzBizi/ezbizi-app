<?php

namespace Tests\Feature\Scheduling;

use App\Models\ServiceArea;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Scheduling › Settings › Technicians, EzBizi's own roster (Jose, 2026-10-27). */
class SchedulingRosterTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_editor_adds_and_retires_a_technician_with_their_areas(): void
    {
        $t = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($t['org']);
        $north = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'North']);
        $texas = $this->extraFranchise($t, 'Texas');
        $theirs = $this->inTenant($t['org'], fn () => ServiceArea::create(['franchise_id' => $texas->id, 'name' => 'Dallas']));
        $as = $this->signedInAs($t['admin']);

        $id = $as->postJson('/api/v1/scheduling/roster', ['franchise_id' => $t['franchise']->id, 'name' => 'Ron', 'phone' => '555-0101', 'area_ids' => [$north->id, $theirs->id]])
            ->assertCreated()->json('data.id');
        $this->assertSame(['North'], array_column($as->getJson('/api/v1/scheduling/roster')->json('data.0.areas'), 'name'), "another branch's area is not taken");

        $as->patchJson("/api/v1/scheduling/roster/{$id}", ['is_active' => false])->assertOk();
        $this->assertSame([], $as->getJson('/api/v1/scheduling/roster')->json('data'));
        $this->assertCount(1, $as->getJson('/api/v1/scheduling/roster?all=1')->json('data'));

        $reader = $this->userWithRole($t, 'Reader', ['scheduling.view', 'scheduling.settings.view']);
        $this->assertContains($t['admin']->id, array_column($this->signedInAs($reader)->getJson('/api/v1/scheduling/roster')->assertOk()->json('users'), 'id'));
        $this->signedInAs($reader)->patchJson("/api/v1/scheduling/roster/{$id}", ['name' => 'X'])->assertForbidden();
    }
}
