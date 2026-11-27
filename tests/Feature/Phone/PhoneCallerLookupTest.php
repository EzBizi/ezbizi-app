<?php

namespace Tests\Feature\Phone;

use App\Models\Customer;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Who is ringing, for the incoming-call window.
 *
 * The softphone learns a caller from the Voice SDK, which gives it a number
 * and nothing else. Jose asked for the pop-up to show a NAME when we know one,
 * so something has to turn one into the other.
 */
class PhoneCallerLookupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_number_we_hold_comes_back_with_its_name(): void
    {
        [$t, $desk] = $this->line();

        $this->inTenant($t['org'], fn () => Customer::factory()->create([
            'owning_franchise_id' => $t['franchise']->id,
            'name' => 'Marta Delgado',
            'phone' => '+13055550100',
            'postal_code' => '33401',
        ]));

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/caller?e164=%2B13055550100')
            ->assertOk()
            ->assertJsonPath('data.name', 'Marta Delgado')

            // The Dial pad's Availability panel opens on it.
            ->assertJsonPath('data.postal_code', '33401');
    }

    /**
     * And one we do not hold comes back named nothing.
     *
     * The control. Without it the case above passes on a build that returns
     * the first customer in the table for any number at all — which on a
     * ringing phone means telling somebody the wrong person is calling, and
     * that is worse than telling them nobody.
     */
    public function test_an_unknown_number_names_nobody(): void
    {
        [$t, $desk] = $this->line();

        $this->inTenant($t['org'], fn () => Customer::factory()->create([
            'owning_franchise_id' => $t['franchise']->id,
            'name' => 'Marta Delgado',
            'phone' => '+13055550100',
        ]));

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/caller?e164=%2B13055550199')
            ->assertOk()
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.customer_id', null);
    }

    /**
     * Another company's customer is not ours to name.
     *
     * The window is drawn by the app shell for anybody whose phone rings, so
     * this endpoint is reachable from every screen in the product — which
     * makes it exactly the kind of small lookup that quietly becomes a way to
     * ask whether a number is in somebody else's database.
     */
    public function test_a_customer_in_another_tenant_is_not_found(): void
    {
        [$t, $desk] = $this->line();
        $other = $this->provisionTenant();

        $this->inTenant($other['org'], fn () => Customer::factory()->create([
            'owning_franchise_id' => $other['franchise']->id,
            'name' => 'Somebody Else',
            'phone' => '+13055550100',
        ]));

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/caller?e164=%2B13055550100')
            ->assertOk()
            ->assertJsonPath('data.name', null);
    }

    /** @return array{0: array<string,mixed>, 1: User} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);

        $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);
        });

        return [$t, $desk];
    }
}
