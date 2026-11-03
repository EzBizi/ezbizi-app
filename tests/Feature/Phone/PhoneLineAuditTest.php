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
                    'account_sid' => $connection->account_sid,
                ]);
            }
            // Held on the parent account, read with the platform credentials.
            PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'twilio_sid' => 'PN4',
                'e164' => '+18555550004',
                'account_sid' => 'ACparent',
            ]);
        });

        $line = fn ($sid, $e164, $voice) => ['sid' => $sid, 'phone_number' => $e164, 'friendly_name' => '', 'voice_url' => $voice, 'sms_url' => $host.'/api/v1/phone/sms'];
        $twilio->incoming = [
            $line('PN1', '+12035550001', $host.'/api/v1/phone/voice'),
            $line('PN2', '+12035550002', 'https://elsewhere.example/voice'),
        ];
        $twilio->accountIncoming['ACparent'] = [$line('PN4', '+18555550004', 'https://elsewhere.example/voice')];

        $this->artisan('ezbizi:phone-line-audit')
            ->expectsOutput('+12035550002: voice goes to https://elsewhere.example/voice')
            ->expectsOutput('+12035550003: gone from Twilio')
            ->expectsOutput('+18555550004: voice goes to https://elsewhere.example/voice')
            ->doesntExpectOutput('+12035550001: gone from Twilio')
            ->assertSuccessful();

        // The known exception stays quiet about voice, and only about voice.
        $twilio->accountIncoming['ACparent'] = [$line('PN5', '+18559553322', 'https://elsewhere.example/voice')];
        $this->inTenant($t['org'], fn () => PhoneNumber::query()->where('twilio_sid', 'PN4')->update(['twilio_sid' => 'PN5', 'e164' => '+18559553322']));
        $this->artisan('ezbizi:phone-line-audit')
            ->doesntExpectOutput('+18559553322: voice goes to https://elsewhere.example/voice')
            ->assertSuccessful();

        $twilio->incoming[] = $line('PN9', '+12035550009', $host.'/api/v1/phone/voice');

        $this->artisan('ezbizi:phone-line-audit')
            ->expectsOutput('added on Twilio: +12035550009')
            ->assertSuccessful();
    }
}
