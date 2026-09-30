<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A person's home address in parts (Jose, 2026-09-30): "a full field address
 * that works as a lookup and then the Main Address - Unit - City - State -
 * Country - Zip". The one line contracts print is composed from the parts;
 * a line saved before the parts existed still reads.
 */
class ProfileAddressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->admin = $r['administrator'];
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_the_parts_save_and_compose_the_line_contracts_print(): void
    {
        $this->as()->patchJson('/api/v1/me', [
            'address_line1' => '827 NW 115th St',
            'address_line2' => 'Unit 4',
            'city'          => 'Miami',
            'state'         => 'FL',
            'postal_code'   => '33168',
            'country'       => 'United States',
        ])->assertOk();

        $user = User::withoutGlobalScopes()->find($this->admin->id);
        $this->assertSame('827 NW 115th St, Unit 4, Miami, FL 33168, United States', $user->address);

        $this->as()->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('address_line1', '827 NW 115th St')
            ->assertJsonPath('address_line2', 'Unit 4')
            ->assertJsonPath('city', 'Miami')
            ->assertJsonPath('state', 'FL')
            ->assertJsonPath('postal_code', '33168')
            ->assertJsonPath('country', 'United States');
    }

    public function test_a_line_saved_before_the_parts_reads_in_parts(): void
    {
        User::withoutGlobalScopes()->whereKey($this->admin->id)
            ->update(['address' => '827 nw 115th st, Miami, FL 33168, United States']);

        $this->as()->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('address_line1', '827 nw 115th st')
            ->assertJsonPath('city', 'Miami')
            ->assertJsonPath('state', 'FL')
            ->assertJsonPath('postal_code', '33168')
            ->assertJsonPath('country', 'United States');
    }

    public function test_a_line_that_is_not_plainly_an_address_is_kept_whole(): void
    {
        User::withoutGlobalScopes()->whereKey($this->admin->id)
            ->update(['address' => 'The blue house past the lake']);

        $this->as()->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('address_line1', 'The blue house past the lake')
            ->assertJsonPath('city', null)
            ->assertJsonPath('postal_code', null);
    }

    public function test_editing_one_part_of_a_one_line_address_keeps_the_rest(): void
    {
        User::withoutGlobalScopes()->whereKey($this->admin->id)
            ->update(['address' => '827 nw 115th st, Miami, FL 33168']);

        $this->as()->patchJson('/api/v1/me', ['address_line2' => 'Unit 4'])->assertOk();

        $this->assertSame(
            '827 nw 115th st, Unit 4, Miami, FL 33168',
            User::withoutGlobalScopes()->find($this->admin->id)->address,
        );
    }

    public function test_emptying_every_part_empties_the_address(): void
    {
        User::withoutGlobalScopes()->whereKey($this->admin->id)
            ->update(['address' => 'Old line', 'address_line1' => 'Old line']);

        $this->as()->patchJson('/api/v1/me', [
            'address_line1' => null, 'address_line2' => null, 'city' => null,
            'state' => null, 'postal_code' => null, 'country' => null,
        ])->assertOk();

        $this->assertNull(User::withoutGlobalScopes()->find($this->admin->id)->address);
        $this->assertContains('address', $this->as()->getJson('/api/v1/me')->json('data.profile_missing'));
    }

    public function test_the_lookup_names_the_country(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [[
            'properties' => [
                'housenumber' => '827', 'street' => 'NW 115th St', 'city' => 'Miami',
                'state' => 'Florida', 'postcode' => '33168', 'country' => 'United States',
            ],
        ]]])]);

        $this->as()->getJson('/api/v1/address-lookup?q=827+NW+115th')
            ->assertOk()
            ->assertJsonPath('data.0.address_line1', '827 NW 115th St')
            ->assertJsonPath('data.0.country', 'United States');
    }
}
