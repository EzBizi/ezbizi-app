<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\PushSender;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Push — the only channel that reaches a CLOSED app (plan §12).
 *
 * The bell reaches somebody looking at EzBizi and the email reaches somebody
 * reading their mail. Neither wakes a phone in a pocket, which is what Jose
 * asked for on 2026-09-16 after finding that notifications "only show in the
 * app".
 *
 * ── What these guard, and what they cannot ───────────────────────
 *
 * Not "does Google accept it" — that is FCM's business and the Android module
 * has already proved the credential sends to a real handset. These guard the
 * decisions on THIS side: who gets a message, how many people that counts as,
 * what happens when the credential is absent, and the one that has now gone
 * wrong four times — a channel being switched on while `allows()` has not been
 * taught it exists.
 *
 * Http::fake throughout, because a test that opened a socket to Google would be
 * measuring Google.
 */
class NotificationPushTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/v1/projects/*';

    private const OAUTH = 'https://oauth2.googleapis.com/token';

    /**
     * A service account the signer will actually accept.
     *
     * Generated rather than a fixed string, because `openssl_sign` is real in
     * these tests: an invented private key would throw, and the test would then
     * be measuring my fixture rather than the sender.
     */
    private function configureFcm(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $pem);

        $path = storage_path('framework/testing/fcm-'.Str::uuid7().'.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'client_email' => 'ezbizi-fcm-push@ezbizi-506117.iam.gserviceaccount.com',
            'private_key' => $pem,
        ], JSON_THROW_ON_ERROR));

        config(['services.fcm.project_id' => 'ezbizi-506117', 'services.fcm.credentials' => $path]);

        return $path;
    }

    private function device(array $tenant, string $userId, string $token): DeviceToken
    {
        return $this->inTenant($tenant['org'], fn () => DeviceToken::create([
            'organization_id' => $tenant['org']->id,
            'user_id' => $userId,
            'platform' => 'android',
            'token' => $token,
            'last_registered_at' => now(),
            'created_at' => now(),
        ]));
    }

    private function set(array $tenant, string $key, mixed $value): void
    {
        $this->inTenant($tenant['org'], fn () => app(SettingsResolver::class)->set($key, $tenant['org'], $value));
    }

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    public function test_a_notification_reaches_the_devices_of_the_people_it_is_for(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Alpha');
        $this->device($tenant, $tenant['admin']->id, 'token-admin-phone');

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(1, $told);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send')
            && $request['message']['token'] === 'token-admin-phone'
            && $request['message']['notification']['title'] === 'New Live Chat Lead');
    }

    public function test_one_person_with_two_handsets_is_one_person_told(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Beta');
        $this->device($tenant, $tenant['admin']->id, 'token-phone');
        $this->device($tenant, $tenant['admin']->id, 'token-tablet');

        // The bell is off, so the count can only come from push — otherwise the
        // row count would supply the 1 and this would prove nothing.
        $this->set($tenant, 'notifications.in_app_enabled', false);
        $this->set($tenant, 'notifications.email_enabled', false);

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        // BOTH devices are sent to...
        Http::assertSentCount(3);   // one OAuth token + two messages

        // ...and it is still ONE person told. A colleague with a phone and a
        // tablet must not inflate the sentence a rule author reads.
        $this->assertSame(1, $told);
    }

    /**
     * The trap that has now appeared four times in this file's history.
     *
     * allows() decides whether to resolve an audience at all. It read `in_app`
     * alone, then `in_app || email`, and each time a new channel arrived it
     * answered false for an organization that had that channel switched ON —
     * returning before the one available channel could fire, silently, looking
     * exactly like nobody having reach.
     */
    public function test_push_alone_is_enough_to_resolve_an_audience(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Gamma');
        $this->device($tenant, $tenant['admin']->id, 'token-only-channel');

        $this->set($tenant, 'notifications.in_app_enabled', false);
        $this->set($tenant, 'notifications.email_enabled', false);

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(1, $told, 'push was the only channel left and it had to count');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send'));
    }

    public function test_switching_push_off_leaves_the_bell_working(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Delta');
        $this->device($tenant, $tenant['admin']->id, 'token-should-not-be-used');
        $this->set($tenant, 'notifications.push_enabled', false);

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        // The control: the notification itself still happened.
        $this->assertSame(1, $told);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'messages:send'));
    }

    public function test_a_server_with_no_credential_has_no_push_rather_than_a_broken_one(): void
    {
        Http::fake();

        // Every developer machine is in this state, deliberately: the service
        // account lives on the box and is never committed. It must read as "no
        // push channel", not as a fault thrown from a queue worker.
        config(['services.fcm.credentials' => null]);

        $tenant = $this->provisionTenant('Epsilon');
        $this->device($tenant, $tenant['admin']->id, 'token-unreachable');

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(1, $told, 'the bell and the email are unaffected');
        $this->assertFalse(app(PushSender::class)->configured());
        Http::assertNothingSent();
    }

    /**
     * FCM's answer is the only reliable signal a device is gone.
     *
     * A send to a dead token otherwise succeeds and delivers nothing, so
     * silence is not evidence. If this stops working the table fills with
     * addresses for handsets that were wiped or sold, and every dispatch pays
     * for them.
     */
    public function test_a_token_fcm_says_is_gone_is_deleted(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Zeta');
        $this->device($tenant, $tenant['admin']->id, 'token-uninstalled');

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => DeviceToken::query()->count()));
    }

    public function test_a_handset_registering_twice_is_one_row(): void
    {
        $tenant = $this->provisionTenant('Theta');

        // The Android client presents its token on EVERY launch, so the same
        // value arrives many times. Rows must not accumulate.
        foreach ([1, 2, 3] as $ignored) {
            $this->signedInAs($tenant['admin'])
                ->postJson('/api/v1/notifications/devices', [
                    'token' => 'token-same-handset',
                    'platform' => 'android',
                ])->assertOk();
        }

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => DeviceToken::query()->count()));
    }

    public function test_a_handset_that_changes_hands_moves_rather_than_duplicating(): void
    {
        $tenant = $this->provisionTenant('Iota');
        $colleague = $this->userWithRole($tenant, 'Colleague', []);

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/notifications/devices', [
            'token' => 'token-shared-tablet', 'platform' => 'android',
        ])->assertOk();

        $this->signedInAs($colleague)->postJson('/api/v1/notifications/devices', [
            'token' => 'token-shared-tablet', 'platform' => 'android',
        ])->assertOk();

        // One row, now owned by whoever actually signed in. Two rows would mean
        // the previous person keeps an address for a handset they no longer
        // hold, which is the worst outcome: a notification about a record they
        // may not open, delivered to somebody else's device.
        $devices = $this->inTenant($tenant['org'], fn () => DeviceToken::query()->get());

        $this->assertCount(1, $devices);
        $this->assertSame($colleague->id, $devices->first()->user_id);
    }

    public function test_an_ios_token_is_refused_while_there_is_no_apns_sender(): void
    {
        $tenant = $this->provisionTenant('Kappa');

        // Android measured that the token their plugin exposes on iOS is a
        // PushKit VoIP token — a different credential from an APNs notification
        // token, which wakes an app for a CALL and cannot carry this. Accepting
        // it would store a row that looks registered and can never be delivered
        // to. A refusal is visible; a dead row is not.
        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/notifications/devices', [
                'token' => 'token-pushkit-voip',
                'platform' => 'ios',
            ])->assertStatus(422);

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => DeviceToken::query()->count()));

        // The control: the SAME request with the platform that does have a
        // sender is accepted, so this pins the platform and not a broken route.
        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/notifications/devices', [
                'token' => 'token-pushkit-voip',
                'platform' => 'android',
            ])->assertOk();

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => DeviceToken::query()->count()));
    }

    public function test_a_refusal_that_is_not_unregistered_keeps_the_token(): void
    {
        // The control for the test above. Without it, deleting on EVERY failure
        // would pass just as well — and a transient 503 would then throw away
        // the address of a perfectly good handset.
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Eta');
        $this->device($tenant, $tenant['admin']->id, 'token-still-good');

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => DeviceToken::query()->count()));
    }
}
