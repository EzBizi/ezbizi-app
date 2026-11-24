<?php

namespace Tests\Feature\Automations;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Automations\ActionRunner;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Health sweep 11-24: a phone stored as "860.329.1234" is sent as E.164; an incomplete one is skipped, not sent to Twilio to fail. */
class SmsRecipientNumberTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_dotted_number_is_normalised_and_an_incomplete_one_is_skipped(): void
    {
        $twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $twilio);
        $t = $this->provisionTenant();

        [$dotted, $short] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'status' => PhoneConnection::STATUS_CONNECTED]);
            $line = PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+12035551009', 'sms_outbound_allowed' => true]);
            $event = new AutomationEvent(['event_key' => 'job.status_changed', 'payload' => ['after' => ['status' => 'Done']]]);
            $run = function (string $phone) use ($t, $event, $line) {
                $customer = Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-'.uniqid(), 'name' => 'Marta', 'phone' => $phone]);
                $job = Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id,
                    'number' => 'J-'.uniqid(), 'title' => 'Sweep', 'status' => 'Done', 'currency' => 'USD']);

                return app(ActionRunner::class)->run(['type' => 'sms.send', 'to' => 'customer:', 'number_id' => $line->id, 'body' => 'Thanks!'], $event, $job);
            };

            return [$run('860.329.1234'), $run('860.329')];
        });

        $this->assertTrue($dotted['ok'], $dotted['detail']);
        $this->assertFalse($dotted['skipped'] ?? false, $dotted['detail']);
        $this->assertSame('+18603291234', collect($twilio->calls)->firstWhere('method', 'sendMessage')['args'][2]);
        $this->assertTrue($short['ok'] && ($short['skipped'] ?? false), $short['detail']);
        $this->assertStringContainsString('not a complete phone number', $short['detail']);
        $this->assertCount(1, collect($twilio->calls)->where('method', 'sendMessage'));
    }
}
