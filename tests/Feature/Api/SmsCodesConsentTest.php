<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Phone\HttpTwilioGateway;
use App\Support\Phone\TwilioGateway;
use App\Support\Sms\PlatformSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Consent to be texted a verification code — the record a carrier asks for.
 *
 * These are not ordinary settings tests. A US carrier will refuse to carry a
 * code unless the sender can produce evidence that the recipient agreed to
 * receive it, and the penalty for getting it wrong is not a bug report: the
 * number's registration is pulled and nothing can be texted from it at all.
 *
 * So the things asserted below are the things an auditor asks. Was consent
 * explicit. Is it dated. Can it be withdrawn without an obstacle. Is anything
 * sent to a number nobody has proven they hold.
 */
class SmsCodesConsentTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = ['organization' => $r['organization'], 'admin' => $r['administrator']];
    }

    /** EzBizi holding a number of its own to send from. */
    private function withSender(): void
    {
        // The real gateway, so Http::fake sees the request Twilio would.
        $this->app->bind(TwilioGateway::class, HttpTwilioGateway::class);
        config([
            'services.twilio.platform_account_sid' => 'ACtest',
            'services.twilio.platform_auth_token' => 'token',
            'services.twilio.platform_sms_from' => '+18559553322',
        ]);
    }

    private function signedIn(): static
    {
        return $this->actingAs($this->org['admin']);
    }

    private function admin(): User
    {
        return User::withoutGlobalScopes()->findOrFail($this->org['admin']->id);
    }

    /**
     * With no number of its own, EzBizi offers nothing.
     *
     * The alternative is a form that takes a mobile number and a consent for a
     * message that cannot be sent: an agreement nobody can act on, and
     * somebody waiting for a code that was never going to arrive.
     */
    public function test_the_form_is_unavailable_until_ezbizi_has_a_sender(): void
    {
        config(['services.twilio.platform_sms_from' => null]);

        $this->signedIn()
            ->getJson('/api/v1/me/sms-codes')
            ->assertOk()
            ->assertJsonPath('data.available', false);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '3055550199', 'consent' => 'accepted'])
            ->assertStatus(422);

        $this->assertNull($this->admin()->sms_code_number);
    }

    /**
     * Consent must be POSITIVELY given, and a missing field is not consent.
     *
     * This is why the wire format is the word `accepted` rather than a
     * boolean. A boolean arriving false is indistinguishable from a client
     * that forgot to send the field, and one of those two readings is a
     * carrier violation.
     */
    public function test_a_number_without_consent_is_refused(): void
    {
        $this->withSender();
        Http::fake();

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '3055550199'])
            ->assertStatus(422);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '3055550199', 'consent' => 'false'])
            ->assertStatus(422);

        $this->assertNull(
            $this->admin()->sms_code_number,
            'a mobile number was stored without anybody agreeing to it',
        );

        Http::assertNothingSent();
    }

    /**
     * Consent is stored WITH THE MOMENT IT WAS GIVEN.
     *
     * "Did they consent" is answerable by a boolean; "when, and to what" is
     * not, and the second is what a toll-free verification audit actually
     * asks. The timestamp is written in the same statement as the number, so
     * a number can never exist here without one.
     */
    public function test_consent_is_recorded_with_its_date_and_the_code_is_sent(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '(305) 555-0199', 'consent' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        $user = $this->admin();

        $this->assertSame('+13055550199', $user->sms_code_number, 'not stored as E.164');
        $this->assertNotNull($user->sms_code_consent_at, 'consent has no date on it');
        $this->assertNull($user->sms_code_confirmed_at, 'unconfirmed number counted as confirmed');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'Messages.json')
                && $request['To'] === '+13055550199'
                && $request['From'] === '+18559553322'
                && str_contains($request['Body'], 'EzBizi verification code');
        });
    }

    /**
     * The code itself is never readable in the database.
     *
     * It is a password for the ten minutes it lives, and a code sitting in
     * plain text in a backup is a code somebody can use.
     */
    public function test_the_code_is_stored_hashed_and_expires(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '3055550199', 'consent' => 'accepted'])
            ->assertOk();

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'Messages.json')) {
                preg_match('/(\d{6})/', $request['Body'], $m);
                $sent = $m[1] ?? null;
            }

            return true;
        });

        $user = $this->admin();

        $this->assertNotNull($sent, 'no six-digit code was in the message');
        $this->assertNotSame($sent, $user->sms_code_hash, 'the code is readable in the database');
        $this->assertNotNull($user->sms_code_expires_at);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes/confirm', ['code' => $sent])
            ->assertOk()
            ->assertJsonPath('data.confirmed', true);

        $this->assertNotNull($this->admin()->sms_code_confirmed_at);
    }

    /**
     * A wrong code confirms nothing, and an expired one is refused even when
     * the digits are right.
     */
    public function test_a_wrong_or_expired_code_does_not_confirm(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '3055550199', 'consent' => 'accepted'])
            ->assertOk();

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($this->admin()->sms_code_confirmed_at);

        // Now let it go stale with the right code still outstanding.
        User::withoutGlobalScopes()
            ->whereKey($this->org['admin']->id)
            ->update(['sms_code_expires_at' => now()->subMinute()]);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes/confirm', ['code' => '123456'])
            ->assertStatus(422);

        $this->assertNull($this->admin()->sms_code_confirmed_at);
    }

    /**
     * Withdrawing consent asks for nothing.
     *
     * Turning the AUTHENTICATOR off requires a password, deliberately —
     * stripping somebody's second factor is an attack. Stopping messages is
     * the opposite: an opt-out with an obstacle in front of it is the thing
     * STOP exists to make impossible, and a carrier treats it as one.
     */
    public function test_opting_out_needs_no_password_and_clears_everything(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '3055550199', 'consent' => 'accepted'])
            ->assertOk();

        $this->signedIn()
            ->deleteJson('/api/v1/me/sms-codes')
            ->assertOk();

        $user = $this->admin();

        $this->assertNull($user->sms_code_number);
        $this->assertNull($user->sms_code_consent_at);
        $this->assertNull($user->sms_code_confirmed_at);
        $this->assertNull($user->sms_code_hash);
    }

    /**
     * The profile phone is left alone, and is never texted.
     *
     * `users.phone` is a field an administrator often filled in during
     * onboarding. Nobody agreed to anything by having it typed for them, and
     * texting it would be texting a number that never opted in — which is
     * precisely what carriers police.
     */
    public function test_the_profile_phone_is_neither_used_nor_changed(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        User::withoutGlobalScopes()
            ->whereKey($this->org['admin']->id)
            ->update(['phone' => '+15615550143']);

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '3055550199', 'consent' => 'accepted'])
            ->assertOk();

        $this->assertSame('+15615550143', $this->admin()->phone, 'the profile phone was overwritten');

        Http::assertSent(fn ($request) => $request['To'] !== '+15615550143');
    }

    /**
     * A number with no country code is refused rather than guessed at.
     *
     * The dial pad learned this the hard way: `+3055550107` reached Twilio as
     * a real-looking destination and came back unroutable, and the same shape
     * here would send a code into nothing while reporting success.
     */
    public function test_an_unusable_number_is_refused_rather_than_guessed(): void
    {
        $this->withSender();
        Http::fake();

        $this->signedIn()
            ->postJson('/api/v1/me/sms-codes', ['phone' => '555 0199', 'consent' => 'accepted'])
            ->assertStatus(422);

        $this->assertNull($this->admin()->sms_code_number);

        Http::assertNothingSent();
    }

    // ── rate limits (audit 2026-11-27) ───────────────────────────

    private function ask(string $phone = '3055550199')
    {
        return $this->signedIn()->postJson('/api/v1/me/sms-codes', ['phone' => $phone, 'consent' => 'accepted']);
    }

    private function textsSent(): int
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'Messages.json'))
            ->count();
    }

    /** Every send is a paid text: a loop on this endpoint is toll fraud. */
    public function test_sends_are_capped_per_person(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        config(['ezbizi.sms_codes.sends_per_user_per_hour' => 3]);

        $this->ask('3055550101')->assertOk();
        $this->ask('3055550102')->assertOk();
        $this->ask('3055550103')->assertOk();
        $this->ask('3055550104')->assertStatus(429)->assertJsonPath('code', 'throttled');

        $this->assertSame(3, $this->textsSent());
    }

    /** And per destination, so one number cannot be pumped however it is asked for. */
    public function test_sends_are_capped_per_number(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        config([
            'ezbizi.sms_codes.sends_per_user_per_hour' => 100,
            'ezbizi.sms_codes.sends_per_number_per_day' => 2,
        ]);

        $this->ask()->assertOk();
        $this->ask()->assertOk();
        $this->ask()->assertStatus(429);

        // Another number is still fine: the cap is on the destination.
        $this->ask('3055550188')->assertOk();

        $this->assertSame(3, $this->textsSent());
    }

    /**
     * US and Canada only. +1 alone is not enough: it also reaches Caribbean
     * nations and premium-rate codes, the destinations toll fraud pumps.
     */
    public function test_only_us_and_canadian_numbers_are_texted(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        foreach (['+44 7700 900123', '+1 876 555 0199', '+1 900 555 0199', '+1 809 555 0199'] as $phone) {
            $this->ask($phone)->assertStatus(422);
        }

        $this->assertSame(0, $this->textsSent());
        $this->assertNull($this->admin()->sms_code_number);

        $this->ask('+1 416 555 0199')->assertOk();
        $this->assertSame(1, $this->textsSent());
    }

    /** A handful of wrong guesses kills the code; six digits do not survive a script. */
    public function test_wrong_guesses_kill_the_code(): void
    {
        $this->withSender();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        config(['ezbizi.sms_codes.confirm_attempts' => 5]);

        $this->ask()->assertOk();

        $sent = null;
        foreach (Http::recorded() as [$request]) {
            if (preg_match('/(\d{6})/', (string) ($request['Body'] ?? ''), $m)) {
                $sent = $m[1];
            }
        }
        $this->assertNotNull($sent);
        $wrong = $sent === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->signedIn()->postJson('/api/v1/me/sms-codes/confirm', ['code' => $wrong])->assertStatus(422);
        }

        // The right code no longer works: the one that was guessed at is gone.
        $this->signedIn()->postJson('/api/v1/me/sms-codes/confirm', ['code' => $sent])->assertStatus(429);
        $this->assertNull($this->admin()->sms_code_confirmed_at);
        $this->assertNull($this->admin()->sms_code_hash);
    }

    /** With nothing configured the sender says so rather than throwing. */
    public function test_the_sender_reports_itself_unconfigured(): void
    {
        config(['services.twilio.platform_sms_from' => null]);

        $this->assertFalse(app(PlatformSms::class)->configured());
        $this->assertFalse(app(PlatformSms::class)->send('+13055550199', 'hello'));
    }
}
