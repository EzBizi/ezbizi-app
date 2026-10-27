<?php

namespace Tests\Feature\Sms;

use App\Support\Sms\PlatformSms;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EzBizi's own number is on a different account from the one it signs as.
 *
 * ── Why these are two accounts ───────────────────────────────────
 *
 * `+18777373929` moved off the Twilio parent and onto the EzBizi subaccount on
 * 2026-09-16, at Jose's word, because the number belongs to EzBizi the
 * business and the parent is the platform every tenant hangs off.
 *
 * Twilio scopes a `From` to the account that holds the number: sending it from
 * the parent is refused with 21606, which reads like a bad phone number rather
 * than a number on the wrong account. So the URL names the account holding the
 * number, while the credentials stay the parent's — which Twilio accepts for
 * its own subaccounts.
 */
class PlatformSmsAccountTest extends TestCase
{
    /** The real gateway, so Http::fake sees the request Twilio would. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(\App\Support\Phone\TwilioGateway::class, \App\Support\Phone\HttpTwilioGateway::class);
    }

    /**
     * The number's account is in the URL; the parent's credentials sign it.
     */
    public function test_the_send_addresses_the_account_the_number_is_on(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        config([
            'services.twilio.platform_account_sid' => 'ACparent',
            'services.twilio.platform_auth_token' => 'a-parent-token',
            'services.twilio.platform_sms_from' => '+18777373929',
            'services.twilio.platform_sms_account_sid' => 'ACsubaccount',
        ]);

        $this->assertTrue((new PlatformSms)->send('+13055550100', '123456 is your code.'));

        Http::assertSent(function ($request) {
            $this->assertStringContainsString(
                '/Accounts/ACsubaccount/Messages.json',
                $request->url(),
                'the message was addressed to the account that does not hold the number',
            );

            // THE HALF THAT IS NOT THE URL. Repointing the account by moving
            // the credentials with it would fail in a way this test could not
            // see: the auth stays the parent's, deliberately.
            $this->assertSame(
                'Basic '.base64_encode('ACparent:a-parent-token'),
                $request->header('Authorization')[0] ?? '',
            );

            return true;
        });
    }

    /**
     * Unset, it sends from the account it signs as.
     *
     * The control, and it has to use a value that DIFFERS from the one above,
     * or a build that hard-codes either account passes both halves.
     */
    public function test_with_no_override_it_sends_from_the_signing_account(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        config([
            'services.twilio.platform_account_sid' => 'ACparent',
            'services.twilio.platform_auth_token' => 'a-parent-token',
            'services.twilio.platform_sms_from' => '+18777373929',
            'services.twilio.platform_sms_account_sid' => null,
        ]);

        $this->assertTrue((new PlatformSms)->send('+13055550100', '123456 is your code.'));

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('/Accounts/ACparent/Messages.json', $request->url());

            return true;
        });
    }
}
