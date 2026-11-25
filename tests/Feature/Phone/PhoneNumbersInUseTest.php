<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-05: "list of phones not in use, deactivated or not assigned".
 * The numbers list says when each line last had a call and who holds it.
 */
class PhoneNumbersInUseTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_line_says_its_last_call_and_who_holds_it(): void
    {
        $t = $this->provisionTenant();
        $reader = $this->userWithRole($t, 'Office', ['phone.view', 'phone.settings.view']);
        $ana = $this->userWithRole($t, 'Analorena Ventoso', ['phone.view']);

        [$busy, $quiet] = $this->inTenant($t['org'], function () use ($t, $reader, $ana) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $make = fn (string $e164) => PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => $e164,
            ]);
            $busy = $make('+13055550115');
            $quiet = $make('+13055550116');

            foreach ([$busy, $quiet] as $n) {
                PhoneNumberGrant::create(['organization_id' => $t['org']->id, 'number_id' => $n->id, 'role_id' => $reader->role_id]);
            }

            PhoneCall::query()->create([
                'connection_id' => $connection->id,
                'number_id' => $busy->id,
                'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
                'direction' => PhoneCall::DIRECTION_INBOUND,
                'from_e164' => '+13055550100',
                'to_e164' => $busy->e164,
                'status' => PhoneCall::STATUS_COMPLETED,
            ]);

            PhoneExtension::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $ana->id,
                'number_id' => $busy->id,
                'extension' => '0101',
            ]);

            return [$busy, $quiet];
        });

        $rows = collect($this->signedInAs($reader)->getJson('/api/v1/phone/numbers')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertNotNull($rows[$busy->id]['last_call_at']);
        $this->assertSame('Analorena Ventoso', $rows[$busy->id]['assigned_to']);

        // The control: a line with neither says so, rather than borrowing.
        $this->assertNull($rows[$quiet->id]['last_call_at']);
        $this->assertNull($rows[$quiet->id]['assigned_to']);
    }
}
