<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PhoneLineAuditTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_reports_repointed_missing_and_added_lines(): void
    {
        $twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $twilio);
        $t = $this->provisionTenant();
        $host = rtrim((string) config('app.url'), '/');

        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            foreach (['PN1' => '+12035550001', 'PN2' => '+12035550002', 'PN3' => '+12035550003'] as $sid => $e164) {
                PhoneNumber::factory()->create([
                    'connection_id' => $connection->id,
                    'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                    'scope_id' => $t['org']->id,
                    'twilio_sid' => $sid,
                    'e164' => $e164,
                ]);
            }
        });

        $line = fn ($sid, $e164, $voice) => ['sid' => $sid, 'phone_number' => $e164, 'friendly_name' => '', 'voice_url' => $voice, 'sms_url' => $host.'/api/v1/phone/sms'];
        $twilio->incoming = [
            $line('PN1', '+12035550001', $host.'/api/v1/phone/voice'),
            $line('PN2', '+12035550002', 'https://elsewhere.example/voice'),
        ];

        $this->artisan('ezbizi:phone-line-audit')
            ->expectsOutput('+12035550002: voice goes to https://elsewhere.example/voice')
            ->expectsOutput('+12035550003: gone from Twilio')
            ->doesntExpectOutput('+12035550001: gone from Twilio')
            ->assertSuccessful();

        $twilio->incoming[] = $line('PN9', '+12035550009', $host.'/api/v1/phone/voice');

        $this->artisan('ezbizi:phone-line-audit')
            ->expectsOutput('added on Twilio: +12035550009')
            ->assertSuccessful();
    }
}
