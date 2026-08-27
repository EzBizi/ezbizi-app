<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneServiceRequest;
use App\Models\PhoneServiceRequestCompany;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Taking over the lines an adopted Twilio account already holds (spec §6).
 *
 * A-1 Chimney's account had five numbers on it and EzBizi listed none, because
 * every path assumed a number arrives by being bought. Adopting an account
 * without adopting its numbers leaves a connected screen that looks empty.
 */
class PhoneNumberImportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: FakeTwilioGateway} */
    private function connectedCompany(): array
    {
        config([
            'services.twilio.platform_account_sid' => 'ACplatformplatformplatformplatfor',
            'services.twilio.platform_api_key_sid' => 'SKplatformplatformplatformplatfor',
            'services.twilio.platform_api_secret' => 'platform-secret',
            'app.url' => 'https://app.ezbizi.com',
        ]);

        $t = $this->provisionTenant();

        $connection = $this->inTenant($t['org'], function () use ($t) {
            $request = PhoneServiceRequest::create([
                'status' => PhoneServiceRequest::STATUS_APPROVED,
                'expected_volume' => 'under_100',
                'wants_sms' => false,
                'purpose' => 'Booking chimney sweeps and calling customers back.',
            ]);
            PhoneServiceRequestCompany::create([
                'request_id' => $request->id,
                'company_id' => $t['company']->id,
                'approved' => true,
            ]);

            $c = new PhoneConnection([
                'organization_id' => $t['org']->id,
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'ACadoptedadoptedadoptedadopteda',
                'api_key_sid' => 'SKadoptedadoptedadoptedadopteda',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $c->writeSecrets('secret', 'the-auth-token');
            $c->save();

            return $c;
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->incoming = [
            [
                'sid' => 'PN1111111111111111111111111111111',
                'phone_number' => '+15612323929',
                'friendly_name' => 'EZBZ',
                'voice_url' => 'https://app.a-1chimney.com/api/voice',
                'sms_url' => '',
            ],
            [
                'sid' => 'PN2222222222222222222222222222222',
                'phone_number' => '+19737820956',
                'friendly_name' => 'Bar',
                'voice_url' => '',
                'sms_url' => '',
            ],
        ];

        return [$t, $connection, $gateway];
    }

    public function test_it_lists_lines_the_account_holds_that_ezbizi_has_no_row_for(): void
    {
        [$t, , ] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $response = $this->signedInAs($editor)->getJson(
            '/api/v1/phone/numbers/importable?scope_type=company&scope_id='.$t['company']->id,
        )->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('+15612323929', $response->json('data.0.e164'));

        // The first still answers to A1 Tools, and the screen says so before
        // anybody presses import.
        $this->assertTrue($response->json('data.0.answers_elsewhere'));
        $this->assertFalse($response->json('data.1.answers_elsewhere'));
    }

    public function test_importing_points_the_carrier_at_ezbizi_and_writes_the_row(): void
    {
        [$t, , $gateway] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/numbers/import', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'sids' => ['PN1111111111111111111111111111111'],
        ])->assertCreated();

        $row = $this->inTenant($t['org'], fn () => PhoneNumber::query()->first());
        $this->assertSame('+15612323929', $row->e164);
        $this->assertSame('EZBZ', $row->label);

        // The point of the whole endpoint: the carrier now calls us. A row
        // without this is a line EzBizi lists and never hears from.
        $rewrite = null;
        foreach ($gateway->calls as $call) {
            if ($call['method'] === 'updateIncoming') {
                $rewrite = $call['args'][2];
            }
        }

        $this->assertNotNull($rewrite, 'The carrier was never repointed.');
        $this->assertStringStartsWith('https://app.ezbizi.com/api/v1/phone/voice/', $rewrite['voiceUrl']);
        $this->assertStringStartsWith('https://app.ezbizi.com/api/v1/phone/sms/', $rewrite['smsUrl']);
    }

    public function test_a_line_already_imported_is_not_offered_or_duplicated(): void
    {
        [$t, , ] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $body = [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'sids' => ['PN1111111111111111111111111111111'],
        ];

        $this->signedInAs($editor)->postJson('/api/v1/phone/numbers/import', $body)->assertCreated();
        $this->signedInAs($editor)->postJson('/api/v1/phone/numbers/import', $body)->assertCreated();

        $this->assertSame(1, $this->inTenant($t['org'], fn () => PhoneNumber::query()->count()));

        $response = $this->signedInAs($editor)->getJson(
            '/api/v1/phone/numbers/importable?scope_type=company&scope_id='.$t['company']->id,
        )->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('+19737820956', $response->json('data.0.e164'));
    }
}
