<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * PhoneCall's personal-data declaration says "the purge also asks Twilio to
 * delete the recording". Until 2026-10-09 nothing called deleteRecording(), so
 * an expired call's audio stayed on Twilio (ponytail audit, via the desk).
 */
class PhoneRecordingPurgeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function oldCall(array $t, string $recordingSid, int $monthsAgo): PhoneCall
    {
        return $this->inTenant($t['org'], function () use ($t, $recordingSid, $monthsAgo) {
            $connection = PhoneConnection::query()->first() ?? PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            $number = PhoneNumber::query()->first() ?? PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $call = PhoneCall::query()->create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
                'direction' => PhoneCall::DIRECTION_INBOUND,
                'from_e164' => '+15550001111',
                'to_e164' => $number->e164,
                'status' => PhoneCall::STATUS_COMPLETED,
                'recording_sid' => $recordingSid,
            ]);
            $call->forceFill(['created_at' => now()->subMonths($monthsAgo)])->save();

            return $call;
        });
    }

    public function test_expiry_deletes_the_twilio_recording_and_keeps_a_row_twilio_refused(): void
    {
        $t = $this->provisionTenant();
        $expired = $this->oldCall($t, 'RE'.str_repeat('a', 32), 6);
        $refused = $this->oldCall($t, 'RE'.str_repeat('b', 32), 6);
        $recent = $this->oldCall($t, 'RE'.str_repeat('c', 32), 1);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->failDeleteRecordingFor = [$refused->recording_sid];

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $asked = collect($gateway->calls)->where('method', 'deleteRecording')->pluck('args.1')->all();
        $this->assertContains($expired->recording_sid, $asked, 'the expired call left its audio on Twilio');
        $this->assertNotContains($recent->recording_sid, $asked);

        $left = PhoneCall::query()->withoutGlobalScopes()->pluck('id')->all();
        $this->assertNotContains($expired->id, $left);
        $this->assertContains($refused->id, $left, 'the SID went while the audio stayed');
        $this->assertContains($recent->id, $left);
    }

    public function test_deleting_a_call_row_deletes_its_twilio_recording(): void
    {
        $t = $this->provisionTenant();
        $call = $this->oldCall($t, 'RE'.str_repeat('d', 32), 1);

        $this->inTenant($t['org'], fn () => $call->delete());

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $this->assertContains(
            $call->recording_sid,
            collect($gateway->calls)->where('method', 'deleteRecording')->pluck('args.1')->all(),
        );
    }
}
