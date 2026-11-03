<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Leads\LeadTexts;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/** Jose, 2026-10-13: confirmations by text, and the customer's answer seen on the lead. */
class LeadTextsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_text_to_a_lead_is_on_its_thread_and_so_is_the_answer(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $token] = $this->line($t, true);
        $lead = $this->lead($t, '+15553334444');

        $this->inTenant($t['org'], fn () => app(LeadTexts::class)->send($lead, 'Your visit is confirmed.', automatic: true));

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $send = collect($gateway->calls)->firstWhere('method', 'sendMessage');
        $this->assertSame(['+12035551004', '+15553334444', "Your visit is confirmed.\n".\App\Support\Phone\SmsConsent::FOOTER], array_slice($send['args'], 1, 3));

        // The answer, and a text from a stranger that is not filed anywhere.
        $url = url('/api/v1/phone/sms/'.$t['org']->id.'/'.$connection->id);
        $this->signed($url, ['MessageSid' => 'SM1', 'AccountSid' => 'AC'.str_repeat('a', 32), 'From' => '+15553334444', 'To' => '+12035551004', 'Body' => 'Thanks, see you then', 'SmsStatus' => 'received'], $token)->assertOk();
        $this->signed($url, ['MessageSid' => 'SM2', 'AccountSid' => 'AC'.str_repeat('a', 32), 'From' => '+15559990000', 'To' => '+12035551004', 'Body' => 'Who is this', 'SmsStatus' => 'received'], $token)->assertOk();

        $thread = $this->inTenant($t['org'], fn () => LeadConversation::query()->where('lead_id', $lead->id)->orderBy('occurred_at')->get());
        $this->assertSame([[LeadTexts::CHANNEL, 'ADVERTISER', "Your visit is confirmed.\n".\App\Support\Phone\SmsConsent::FOOTER, true], [LeadTexts::CHANNEL, 'CONSUMER', 'Thanks, see you then', false]],
            $thread->map(fn ($c) => [$c->channel, $c->participant, $c->text, (bool) $c->is_automatic])->all());
    }

    /** Jose, 2026-10-28: a Re-Scheduling offer showed as "Sent outside EzBizi" and its EZ Texts thread could not be replied to. */
    public function test_a_text_that_opens_an_ez_texts_lead_is_ours_and_can_be_answered(): void
    {
        $t = $this->provisionTenant();
        $this->line($t, true);
        $viewer = $this->userWithRole($t, 'Lead manager', ['leads.view', 'leads.manage', 'leads.ez_texts.view']);
        $lead = $this->lead($t, '+15553334444', Lead::SOURCE_EZ_TEXTS);

        $this->inTenant($t['org'], fn () => app(LeadTexts::class)->send($lead, 'An earlier opening is free.', $viewer));

        $this->signedInAs($viewer)->getJson("/api/v1/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('data.conversations.0.sent_by', $viewer->name)
            ->assertJsonPath('data.can_reply', true)
            ->assertJsonPath('data.cannot_reply_because', null);
    }

    public function test_no_cleared_line_is_said_plainly(): void
    {
        $t = $this->provisionTenant();
        $this->line($t, false);
        $lead = $this->lead($t, '+15553334444');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No texting line is cleared');
        $this->inTenant($t['org'], fn () => app(LeadTexts::class)->send($lead, 'Hello'));
    }

    private function lead(array $t, string $phone, string $source = Lead::SOURCE_LOCAL_SERVICES): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'source' => $source, 'lead_type' => 'PHONE_CALL',
            'remote_id' => uniqid(), 'phone' => $phone, 'phone_digits' => preg_replace('/\D/', '', $phone),
            'status' => 'new', 'received_at' => now(),
        ]));
    }

    private function line(array $t, bool $cleared): array
    {
        return $this->inTenant($t['org'], function () use ($t, $cleared) {
            $token = 'sms-auth-token';
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id, 'auth_token' => $token]);
            $number = PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+12035551004', 'label' => 'Texts', 'sms_outbound_allowed' => $cleared]);

            return [$connection, $number, $token];
        });
    }

    private function signed(string $url, array $params, string $token)
    {
        return $this->post($url, $params, ['X-Twilio-Signature' => (new RequestValidator($token))->computeSignature($url, $params)]);
    }
}
