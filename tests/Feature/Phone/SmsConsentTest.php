<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\PhoneTexts;
use App\Support\Phone\SmsConsent;
use App\Support\Phone\TextRefused;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class SmsConsentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_stop_blocks_footer_on_first_text_only_start_resubscribes(): void
    {
        $twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $twilio);
        $t = $this->provisionTenant();
        $to = '+13057242199';

        $this->inTenant($t['org'], function () use ($t, $twilio, $to) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            $number->forceFill(['sms_outbound_allowed' => true])->save();
            $texts = app(PhoneTexts::class);
            $bodies = fn () => collect($twilio->calls)->where('method', 'sendMessage')->map(fn ($c) => $c['args'][3])->values()->all();

            $texts->send($number, $to, 'Hello');
            $texts->send($number, $to, 'Again');
            $this->assertSame(["Hello\n\n".SmsConsent::FOOTER, 'Again'], $bodies());

            $this->assertSame('STOP', SmsConsent::absorb($t['org']->id, $to, ' stop '));
            $this->assertNull(SmsConsent::absorb($t['org']->id, $to, 'please stop by at 3'));
            try {
                $texts->send($number, $to, 'Blocked');
                $this->fail('An opted-out number was texted.');
            } catch (TextRefused $e) {
                $this->assertSame(SmsConsent::REFUSAL, $e->getMessage());
            }
            $this->assertCount(2, $bodies());

            // Automated texts carry it every time.
            $texts->send($number, '+13057242198', 'Hi', automated: true);
            $texts->send($number, '+13057242198', 'Hi again', automated: true);
            $this->assertSame(["Hi\n\n".SmsConsent::FOOTER, "Hi again\n\n".SmsConsent::FOOTER], array_slice($bodies(), 2));

            SmsConsent::absorb($t['org']->id, $to, 'START');
            $texts->send($number, $to, 'Back');
            $this->assertSame('Back', $bodies()[4]);
        });
    }
}
