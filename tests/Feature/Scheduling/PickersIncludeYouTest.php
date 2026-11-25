<?php

namespace Tests\Feature\Scheduling;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-25: the signed-in person is always in a people picker,
 * whatever the Company·Branch picker says. Scheduling's pickers (roster login,
 * Workiz technician link, helper "For") list the organization's people, so a
 * branch view that is not yours still finds you.
 */
class PickersIncludeYouTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_branch_that_is_not_yours_still_lists_you_in_every_scheduling_people_picker(): void
    {
        $t = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($t, 'Elsewhere');
        $me = $t['admin'];
        $this->assertNotSame($other->id, $me->franchise_id, 'The fixture must view a branch that is not the user\'s own.');

        $as = $this->signedInAs($me);
        $this->assertContains($me->id, collect($as->getJson("/api/v1/scheduling/roster?franchise_id={$other->id}")->assertOk()->json('users'))->pluck('id')->all());
        $this->assertContains($me->id, collect($as->getJson("/api/v1/scheduling/helpers?franchise_id={$other->id}")->assertOk()->json('people'))->pluck('id')->all());
        $this->assertContains($me->id, collect($as->getJson('/api/v1/scheduling/technicians')->assertOk()->json('users'))->pluck('id')->all());
        // Listed to pick, never added: the roster itself stays as it was.
        $this->assertSame([], $as->getJson("/api/v1/scheduling/roster?franchise_id={$other->id}")->json('data'));
    }
}
