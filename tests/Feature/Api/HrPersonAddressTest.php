<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * HR's record takes the home address in parts as Your account does (Jose,
 * 2026-09-30), and composes the one line contracts print from them.
 */
class HrPersonAddressTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_hr_saves_the_parts_and_the_record_reads_them_back(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Dispatcher', ['customers.view']);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", [
                'address_line1' => '827 NW 115th St',
                'city'          => 'Miami',
                'state'         => 'FL',
                'postal_code'   => '33168',
                'country'       => 'United States',
            ])
            ->assertOk();

        $this->assertSame(
            '827 NW 115th St, Miami, FL 33168, United States',
            $this->inTenant($alpha['org'], fn () => User::find($person->id))->address,
        );

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.address_line1', '827 NW 115th St')
            ->assertJsonPath('data.city', 'Miami')
            ->assertJsonPath('data.state', 'FL')
            ->assertJsonPath('data.postal_code', '33168')
            ->assertJsonPath('data.country', 'United States');
    }

    public function test_an_address_written_as_one_line_replaces_the_parts(): void
    {
        // An older client, an importer or onboarding writes `address` alone.
        $alpha  = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Dispatcher', ['customers.view']);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", [
                'address_line1' => '1 Old Rd', 'city' => 'Tampa', 'state' => 'FL', 'postal_code' => '33601',
            ])
            ->assertOk();

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['address' => '827 NW 115th St, Miami, FL 33168'])
            ->assertOk();

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.address_line1', '827 NW 115th St')
            ->assertJsonPath('data.city', 'Miami');

        // And editing one part afterwards builds on the new address, not the old.
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['address_line2' => 'Unit 4'])
            ->assertOk();

        $this->assertSame(
            '827 NW 115th St, Unit 4, Miami, FL 33168',
            $this->inTenant($alpha['org'], fn () => User::find($person->id))->address,
        );
    }

    public function test_a_record_saved_as_one_line_reads_in_parts(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Dispatcher', ['customers.view']);
        $this->inTenant($alpha['org'], fn () => User::whereKey($person->id)
            ->update(['address' => '827 nw 115th st 827 nw 115th st, Miami, FL 33168, United States']));

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.address_line1', '827 nw 115th st 827 nw 115th st')
            ->assertJsonPath('data.city', 'Miami')
            ->assertJsonPath('data.postal_code', '33168');
    }
}
