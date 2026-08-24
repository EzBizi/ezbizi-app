<?php

namespace Tests\Feature\Phone;

use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Buy / list / release phone numbers (spec §4–§5, Task 6).
 *
 * Buy uses the resolved connection for the chosen scope. Grants compose with
 * phone.view; sibling companies never share lines.
 */
class PhoneNumberTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_buying_by_area_code_stores_the_number_on_the_resolved_connection(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/numbers', [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'area_code' => '203',
            'label' => 'Main',
        ]);

        $response->assertCreated();
        $this->assertSame('+12030101001', $response->json('data.e164'));
        $this->assertSame($connection->id, $response->json('data.connection_id'));

        $methods = array_column($gateway->calls, 'method');
        $this->assertContains('searchAvailable', $methods);
        $this->assertContains('purchaseIncoming', $methods);

        $row = $this->inTenant($t['org'], fn () => PhoneNumber::query()->first());
        $this->assertNotNull($row);
        $this->assertSame($connection->id, $row->connection_id);
        $this->assertSame('organization', $row->scope_type);
        $this->assertSame($t['org']->id, $row->scope_id);
    }

    public function test_buying_without_a_connection_names_the_gap(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/numbers', [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'area_code' => '203',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['scope_id']);
        $message = (string) $response->json('errors.scope_id.0');
        $this->assertSame('Connect Twilio for this place first.', $message);
        $this->assertStringNotContainsString('PhoneConnection', $message);
        $this->assertStringNotContainsString('ConnectionResolver', $message);
    }

    public function test_a_technician_does_not_see_a_number_their_role_was_not_granted(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Technician', ['phone.view']);

        [$granted, $hidden] = $this->inTenant($t['org'], function () use ($t, $viewer) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $granted = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035550101',
                'label' => 'Granted',
            ]);
            $hidden = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035550102',
                'label' => 'Hidden',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $granted->id,
                'role_id' => $viewer->role_id,
            ]);

            return [$granted, $hidden];
        });

        $rows = $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/numbers')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($granted->id, $rows[0]['id']);
        $this->assertSame('+12035550101', $rows[0]['e164']);
        $this->assertNotContains($hidden->id, array_column($rows, 'id'));
    }

    public function test_an_administrator_sees_every_number_in_reach_without_grants(): void
    {
        $t = $this->provisionTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035550111',
            ]);
            PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_FRANCHISE,
                'scope_id' => $t['franchise']->id,
                'e164' => '+12035550112',
            ]);
        });

        $rows = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/phone/numbers')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
    }

    public function test_georgia_does_not_see_connecticuts_numbers(): void
    {
        $t = $this->provisionTenant('East');

        [$georgiaFranchise, $ctNumberId] = $this->inTenant($t['org'], function () use ($t) {
            $connecticut = $t['company'];
            $georgia = Company::create([
                'organization_id' => $t['org']->id,
                'name' => 'Georgia',
            ]);
            $georgiaFranchise = $georgia->franchises()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Atlanta',
            ]);

            $orgConn = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $ctNumber = PhoneNumber::factory()->create([
                'connection_id' => $orgConn->id,
                'scope_type' => PhoneNumber::SCOPE_COMPANY,
                'scope_id' => $connecticut->id,
                'e164' => '+12035550201',
                'label' => 'Connecticut',
            ]);
            PhoneNumber::factory()->create([
                'connection_id' => $orgConn->id,
                'scope_type' => PhoneNumber::SCOPE_FRANCHISE,
                'scope_id' => $georgiaFranchise->id,
                'e164' => '+14045550202',
                'label' => 'Atlanta',
            ]);

            return [$georgiaFranchise, $ctNumber->id];
        });

        $branch = $this->userWithRole(
            $t,
            'Georgia phone',
            ['phone.view', 'phone.edit'],
            onlyAt: $georgiaFranchise,
        );

        $this->inTenant($t['org'], function () use ($t, $branch, $ctNumberId, $georgiaFranchise) {
            $gaNumber = PhoneNumber::query()
                ->where('scope_type', PhoneNumber::SCOPE_FRANCHISE)
                ->where('scope_id', $georgiaFranchise->id)
                ->firstOrFail();

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $gaNumber->id,
                'role_id' => $branch->role_id,
            ]);
            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $ctNumberId,
                'role_id' => $branch->role_id,
            ]);
        });

        $rows = $this->signedInAs($branch)
            ->getJson('/api/v1/phone/numbers')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('+14045550202', $rows[0]['e164']);
        $this->assertNotContains($ctNumberId, array_column($rows, 'id'));
    }

    public function test_releasing_a_number_calls_twilio_and_deletes_the_row(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $number = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            return PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'twilio_sid' => 'PNcccccccccccccccccccccccccccccccc',
                'e164' => '+12035550303',
            ]);
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($editor)
            ->deleteJson('/api/v1/phone/numbers/'.$number->id)
            ->assertOk();

        $this->assertContains('releaseIncoming', array_column($gateway->calls, 'method'));
        $this->inTenant($t['org'], fn () => $this->assertSame(0, PhoneNumber::query()->count()));
    }

    public function test_buying_requires_phone_edit(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Viewer', ['phone.view']);

        $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        $response = $this->signedInAs($viewer)->postJson('/api/v1/phone/numbers', [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'area_code' => '203',
        ]);

        $response->assertForbidden();
        $this->assertStringNotContainsString('PhoneNumber', (string) $response->json('message'));
    }
}
