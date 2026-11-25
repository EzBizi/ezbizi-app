<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Placing a call from a platform with no Twilio Voice SDK.
 *
 * ── Why there is a second way to make a call ─────────────────────
 *
 * The softphone is a browser script. There is no Voice SDK for Windows, macOS
 * or Linux, and none inside this app on Android or iOS — so on four of the
 * five platforms EzBizi runs on, the Dial pad could not place a call at all
 * and said so.
 *
 * This route needs no SDK: Twilio rings the handset the person already has,
 * they answer, and Twilio dials the customer and bridges them. It is an HTTP
 * request, so every platform can do it — including a browser whose microphone
 * was refused.
 */
class PhoneBridgeCallTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private FakeTwilioGateway $twilio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $this->twilio);
    }

    /** @return array{0: array, 1: PhoneNumber} */
    private function line(string $e164 = '+12035554000'): array
    {
        $t = $this->provisionTenant();

        $number = $this->inTenant($t['org'], function () use ($t, $e164) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            return PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => $e164,
            ]);
        });

        return [$t, $number];
    }

    /**
     * Somebody who may use this line, with or without a handset of their own.
     *
     * The GRANT matters: reach ∩ grant is the access rule, and a caller who
     * skipped it would be an administrator rather than a member of staff —
     * which is not who this feature is for.
     */
    private function deskWithPhone(array $t, PhoneNumber $number, string $email, ?string $phone)
    {
        $user = $this->userWithRole($t, 'Desk '.$email, ['phone.view'], $email);

        $this->inTenant($t['org'], function () use ($user, $number, $phone) {
            PhoneNumberGrant::query()->create([
                'number_id' => $number->id,
                'role_id' => $user->role_id,
            ]);

            if ($phone !== null) {
                $user->forceFill(['phone' => $phone])->save();
            }
        });

        return $user;
    }

    /**
     * The customer sees the COMPANY line, never the employee's own mobile.
     *
     * This is the assertion the whole feature turns on. The call is placed by
     * ringing the employee's personal handset, so the employee's number is
     * right there in the request — and if it reached the inner Dial as the
     * caller ID it would be published to every customer they ever rang, and
     * could not be taken back.
     */
    public function test_the_customer_sees_the_company_line_and_not_the_employee(): void
    {
        [$t, $number] = $this->line('+12035554000');

        $caller = $this->deskWithPhone($t, $number, 'desk@t.test', '+17865550001');

        $this->signedInAs($caller)
            ->postJson('/api/v1/phone/calls/bridge', [
                'number_id' => $number->id,
                'to' => '+13055550107',
            ])
            ->assertCreated();

        $made = collect($this->twilio->calls)->firstWhere('method', 'createCall');
        $this->assertNotNull($made, 'no call was placed at all');

        // Twilio rings the EMPLOYEE, from the company line.
        $args = $made['args'];
        $this->assertSame('+12035554000', $args[1], 'the outer call did not come from the company line');
        $this->assertSame('+17865550001', $args[2], 'the outer call did not ring the employee');

        // And the customer, on the inner Dial, sees the company line.
        $twiml = $args[3];
        $this->assertStringContainsString('callerId="+12035554000"', $twiml);
        $this->assertStringContainsString('+13055550107', $twiml);
        $this->assertStringNotContainsString(
            '+17865550001',
            $twiml,
            "the employee's personal number was about to be shown to the customer",
        );
    }

    /**
     * Nothing to ring means say so, rather than guessing.
     *
     * Falling back to the company line would call EzBizi from EzBizi, and
     * falling back to anybody else would ring a colleague. Neither is
     * recoverable by the person who pressed Call.
     */
    public function test_somebody_with_no_number_of_their_own_is_told_to_add_one(): void
    {
        [$t, $number] = $this->line();

        $caller = $this->deskWithPhone($t, $number, 'nophone@t.test', null);

        $this->signedInAs($caller)
            ->postJson('/api/v1/phone/calls/bridge', [
                'number_id' => $number->id,
                'to' => '+13055550107',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'your own phone number'));

        $this->assertSame([], $this->twilio->calls);
    }

    /**
     * A destination that is not a number at all is refused before dialling.
     *
     * ── What this check can and cannot see ──────────────────────────
     *
     * It sees SHAPE. It does not see that `+305` is not a country, because
     * `+3055550107` is structurally valid E.164 — ten digits behind a plus —
     * which is exactly why it went unchallenged for weeks. Catching that needs
     * a table of real country codes, and pretending a regex does it would be
     * the same overclaim in a new place.
     *
     * The country code is fixed where it is knowable: the dial pad, which knows
     * ten digits typed here means North America. This is the backstop for
     * everything that is not a phone number at all.
     */
    public function test_something_that_is_not_a_number_is_refused_before_dialling(): void
    {
        [$t, $number] = $this->line();

        $caller = $this->deskWithPhone($t, $number, 'bad@t.test', '+17865550001');

        foreach (['3055550107', '+1', 'not a number', ''] as $bad) {
            $this->signedInAs($caller)
                ->postJson('/api/v1/phone/calls/bridge', [
                    'number_id' => $number->id,
                    'to' => $bad,
                ])
                ->assertStatus(422);
        }

        $this->assertSame([], $this->twilio->calls, 'a non-number was dialled anyway');
    }
}
