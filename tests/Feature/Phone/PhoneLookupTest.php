<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Lookup on inbound must never 500 the Call desk (Task 13).
 *
 * Gateway miss / “not enabled” is skipped; the number stays unmatched and the
 * webhook still stores the call or text.
 */
class PhoneLookupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_lookup_miss_does_not_500_on_inbound_sms(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035552001');
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->lookupMiss = true;

        $url = url('/api/v1/phone/sms/'.$t['org']->id.'/'.$connection->id);
        $params = [
            'MessageSid' => 'SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => '+15557654321',
            'To' => '+12035552001',
            'Body' => 'Still store me',
            'SmsStatus' => 'received',
        ];
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ])->assertOk();

        // Audit 11-27: the paid lookup no longer runs on inbound at all.
        $this->assertNotContains('lookup', array_column($gateway->calls, 'method'));

        $this->inTenant($t['org'], function () use ($number) {
            $message = PhoneMessage::query()->first();
            $this->assertNotNull($message);
            $this->assertSame($number->id, $message->number_id);
            $this->assertNull($message->customer_id);
            $this->assertSame('Still store me', $message->body);
        });
    }

    public function test_lookup_not_enabled_does_not_500_on_inbound_voice(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035552002');
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->lookupEnabled = false;

        $url = url('/api/v1/phone/voice/'.$t['org']->id.'/'.$connection->id);
        $params = [
            'CallSid' => 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => '+15551112222',
            'To' => '+12035552002',
            'CallStatus' => 'ringing',
            'Direction' => 'inbound',
        ];
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ])->assertOk();

        // Audit 11-27: the paid lookup no longer runs on inbound at all.
        $this->assertNotContains('lookup', array_column($gateway->calls, 'method'));

        $this->inTenant($t['org'], function () use ($number, $connection) {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame($number->id, $call->number_id);
            $this->assertSame($connection->id, $call->connection_id);
            $this->assertSame('ringing', $call->status);
        });
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneConnection, 1: PhoneNumber, 2: string}
     */
    private function openLine(array $tenant, string $e164): array
    {
        $authToken = 'lookup-auth-'.$e164;
        $connection = PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'auth_token' => $authToken,
        ]);

        $role = Role::query()->firstOrCreate(
            ['organization_id' => $tenant['org']->id, 'name' => 'Lookup desk'],
            ['is_system' => false],
        );
        $user = User::create([
            'organization_id' => $tenant['org']->id,
            'role_id' => $role->id,
            'name' => 'Desk '.$e164,
            'email' => 'desk-'.preg_replace('/\D/', '', $e164).'@'.$tenant['org']->slug.'.test',
            'password' => 'a-real-password',
            'is_active' => true,
        ]);

        PhonePresence::query()->create([
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'state' => PhonePresence::STATE_REGISTERED,
        ]);

        $group = PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'mode' => 'simultaneous',
            'timeout_seconds' => 30,
            'member_user_ids' => [$user->id],
            'member_role_ids' => [],
            'overflow' => 'voicemail',
        ]);

        $number = PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'e164' => $e164,
            'label' => 'Lookup '.$e164,
            'ring_group_id' => $group->id,
            'record' => true,
        ]);

        return [$connection, $number, $authToken];
    }
}
