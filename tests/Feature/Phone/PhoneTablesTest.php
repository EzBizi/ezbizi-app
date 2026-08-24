<?php

namespace Tests\Feature\Phone;

use App\Models\Customer;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Privacy\Anonymiser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Phone tables — connections, numbers, grants, dial plan, calls, texts, presence.
 */
class PhoneTablesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_phone_tables_exist(): void
    {
        foreach ([
            'phone_connections',
            'phone_numbers',
            'phone_number_grants',
            'phone_hours_schedules',
            'phone_ring_groups',
            'phone_flows',
            'phone_calls',
            'phone_messages',
            'phone_presences',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_two_connections_cannot_share_a_scope(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $row = $this->connectionRow($tenant);

        DB::table('phone_connections')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('phone_connections')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
            'account_sid' => 'ACbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);
    }

    public function test_two_numbers_cannot_share_an_e164_in_one_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connectionId = $this->insertConnection($tenant);

        $row = $this->numberRow($tenant, $connectionId);

        DB::table('phone_numbers')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('phone_numbers')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
            'twilio_sid' => 'PNbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);
    }

    public function test_a_connection_secret_is_masked_on_json(): void
    {
        $t = $this->provisionTenant();
        $row = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'credentials' => 'SK-secret-value',
        ]));

        $this->assertStringNotContainsString('SK-secret-value', json_encode($row));
    }

    public function test_a_number_defaults_record_on_and_transcribe_off(): void
    {
        $t = $this->provisionTenant();
        $number = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
            ]);

            return PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
            ]);
        });

        $this->assertTrue($number->record);
        $this->assertFalse($number->transcribe);
        $this->assertContains('record', $number->getFillable());
        $this->assertContains('transcribe', $number->getFillable());
    }

    /**
     * from_e164 / to_e164 are CONTACT about outsiders; Customer erasure must
     * find those legs by matching customers.phone (exact E.164 string).
     */
    public function test_a_customer_purge_drops_calls_that_name_their_number(): void
    {
        $t = $this->provisionTenant();
        $phone = '+15557654321';

        [$customer, $named, $other] = $this->inTenant($t['org'], function () use ($t, $phone) {
            $customer = Customer::create([
                'owning_franchise_id' => $t['franchise']->id,
                'number' => 'C-phone-1',
                'name' => 'Caller',
                'phone' => $phone,
            ]);

            $connection = PhoneConnection::factory()->create([
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
            ]);

            $named = PhoneCall::create([
                'connection_id' => $connection->id,
                'twilio_sid' => 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'direction' => 'inbound',
                'from_e164' => $phone,
                'to_e164' => '+15551110000',
                'status' => 'completed',
            ]);

            $other = PhoneCall::create([
                'connection_id' => $connection->id,
                'twilio_sid' => 'CAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'direction' => 'inbound',
                'from_e164' => '+15550001111',
                'to_e164' => '+15551110000',
                'status' => 'completed',
            ]);

            $this->assertTrue(
                PhoneCall::personalDataAbout($customer)->whereKey($named->id)->exists(),
                'personalDataAbout must link the customer phone to the call leg.',
            );

            return [$customer, $named, $other];
        });

        app(Anonymiser::class)->purge($customer);

        $this->inTenant($t['org'], function () use ($named, $other) {
            $this->assertNull(PhoneCall::find($named->id), 'The named call must be dropped.');
            $this->assertNotNull(PhoneCall::find($other->id), 'Unrelated calls must survive.');
        });
    }

    /**
     * @param  array{org: \App\Models\Organization, company: \App\Models\Company}  $tenant
     */
    private function insertConnection(array $tenant): string
    {
        $id = (string) Str::uuid();
        DB::table('phone_connections')->insert($this->connectionRow($tenant, id: $id));

        return $id;
    }

    /**
     * @param  array{org: \App\Models\Organization, company: \App\Models\Company}  $tenant
     * @return array<string, mixed>
     */
    private function connectionRow(array $tenant, ?string $id = null): array
    {
        $now = now();

        return [
            'id' => $id ?? (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'scope_type' => 'organization',
            'scope_id' => $tenant['org']->id,
            'kind' => 'byo',
            'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'api_key_sid' => null,
            'credentials' => 'SK-placeholder',
            'twiml_app_sid' => null,
            'status' => 'connected',
            'status_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array{org: \App\Models\Organization}  $tenant
     * @return array<string, mixed>
     */
    private function numberRow(array $tenant, string $connectionId): array
    {
        $now = now();

        return [
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'connection_id' => $connectionId,
            'scope_type' => 'organization',
            'scope_id' => $tenant['org']->id,
            'twilio_sid' => 'PNaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'e164' => '+15551234567',
            'label' => 'Main',
            'flow_id' => null,
            'hours_schedule_id' => null,
            'ring_group_id' => null,
            'record' => true,
            'transcribe' => false,
            'after_hours' => 'voicemail',
            'after_hours_number' => null,
            'language' => 'en-US',
            'messaging_service_sid' => null,
            'sms_outbound_allowed' => false,
            'emergency_address_set' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
