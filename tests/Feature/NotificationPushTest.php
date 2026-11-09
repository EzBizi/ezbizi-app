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

    private const APNS = 'https://api.push.apple.com/*';

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
            && $request['message']['notification']['title'] === 'EzBizi - Alpha'
            && $request['message']['notification']['body'] === 'New Live Chat Lead'
            // Named, or FCM delivers on its own generic fallback channel —
            // measured on a real handset. On Android 8+ the channel is what a
            // person sees in Settings and what they MUTE, so a fallback means
            // EzBizi cannot be tuned separately from anything else. Invisible
            // until somebody tries to turn one off.
            && $request['message']['android']['notification']['channel_id'] === 'ezbizi_notifications');
    }

    /**
     * A person who muted their own alerts gets no push — their colleague does.
     *
     * Jose, 2026-10-05: per-user on/off, from the bell panel. The colleague in
     * the same dispatch is the control: their handset IS sent to, so this
     * cannot pass because push stopped working altogether.
     */
    public function test_a_person_who_muted_alerts_gets_no_push_but_a_colleague_does(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Alpha');
        $colleague = $this->userWithRole($tenant, 'Dispatcher');
        $this->device($tenant, $tenant['admin']->id, 'token-muted-phone');
        $this->device($tenant, $colleague->id, 'token-colleague-phone');

        $this->inTenant($tenant['org'], fn () => $tenant['admin']
            ->forceFill(['preferences' => ['notifications.alerts' => false]])->save());

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead',
            subject: $tenant['org'],
            source: 'automation',
        ));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send')
            && $request['message']['token'] === 'token-colleague-phone');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'messages:send')
            && $request['message']['token'] === 'token-muted-phone');
    }

    /**
     * Alerts, new leads and missed calls interrupt; everything else does not.
     *
     * Jose, 2026-10-08: HIGH importance for alerts, calls and new leads,
     * DEFAULT for the rest. The system notification is the control — same
     * person, same handset, same request — so this cannot pass by sending
     * everything on the urgent channel.
     */
    public function test_alerts_and_new_leads_use_the_urgent_channel_and_the_rest_do_not(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Alpha');
        $this->device($tenant, $tenant['admin']->id, 'token-phone');

        $this->inTenant($tenant['org'], function () use ($tenant): void {
            $lead = \App\Models\AutomationEvent::create([
                'organization_id' => $tenant['org']->id, 'event_key' => 'lead.received',
                'payload' => [], 'occurred_at' => now(), 'available_at' => now(),
            ]);

            $this->dispatcher()->dispatch('An alert', onlyTo: $tenant['admin'], source: 'alert');
            $this->dispatcher()->dispatch('New lead', onlyTo: $tenant['admin'], source: 'automation', eventId: $lead->id);
            $this->dispatcher()->dispatch('Trial ends tomorrow', onlyTo: $tenant['admin'], source: 'system');
        });

        $channelOf = fn (string $title) => collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(fn ($request) => str_contains($request->url(), 'messages:send')
                && $request['message']['notification']['body'] === $title)['message']['android']['notification']['channel_id'];

        $this->assertSame('ezbizi_urgent', $channelOf('An alert'));
        $this->assertSame('ezbizi_urgent', $channelOf('New lead'));
        $this->assertSame('ezbizi_notifications', $channelOf('Trial ends tomorrow'));
    }

    /** A rule's "Mark as urgent (red)": stored on the bell row, served, and pushed urgent (Jose, 2026-10-15). */
    public function test_an_urgent_notification_is_marked_on_its_row_and_pushed_urgent(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Alpha');
        $this->device($tenant, $tenant['admin']->id, 'token-phone');

        $this->inTenant($tenant['org'], function () use ($tenant): void {
            $this->dispatcher()->dispatch('Possible complaint call', onlyTo: $tenant['admin'], source: 'automation', urgent: true);
            $this->dispatcher()->dispatch('Weekly summary', onlyTo: $tenant['admin'], source: 'automation');
        });

        $channelOf = fn (string $title) => collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(fn ($request) => str_contains($request->url(), 'messages:send')
                && $request['message']['notification']['body'] === $title)['message']['android']['notification']['channel_id'];
        $this->assertSame('ezbizi_urgent', $channelOf('Possible complaint call'));
        $this->assertSame('ezbizi_notifications', $channelOf('Weekly summary'));

        $rows = collect($this->signedInAs($tenant['admin'])->getJson('/api/v1/notifications')->assertOk()->json('data'))->pluck('urgent', 'title');
        $this->assertSame(true, $rows['Possible complaint call']);
        $this->assertSame(false, $rows['Weekly summary']);
    }

    /**
     * A push carries the id of the recipient's own bell row, so a tap on a
     * phone opens exactly what that row opens (Bar, 2026-10-12).
     *
     * And with the bell OFF there is no row, so no `data` key at all — not an
     * empty one: PHP's empty array encodes as JSON [] and FCM refuses
     * `"data": []`, which would fail every push of a bell-off organization.
     */
    public function test_a_push_names_the_bell_row_a_tap_opens_and_omits_data_without_one(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->configureFcm();
        $tenant = $this->provisionTenant('Alpha');
        $this->device($tenant, $tenant['admin']->id, 'token-phone');

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch('With a row', onlyTo: $tenant['admin']));
        $row = $this->inTenant($tenant['org'], fn () => \App\Models\Notification::where('title', 'With a row')->firstOrFail());

        $this->set($tenant, 'notifications.in_app_enabled', false);
        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch('No row', onlyTo: $tenant['admin']));

        $message = fn (string $title) => collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn ($r) => str_contains($r->url(), 'messages:send') && $r['message']['notification']['body'] === $title);

        $this->assertSame(['notification_id' => $row->id], $message('With a row')['message']['data']);
        $this->assertArrayNotHasKey('data', $message('No row')['message']);
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

    public function test_a_registration_that_loses_the_insert_race_takes_the_row_over(): void
    {
        $tenant = $this->provisionTenant('Kappa');
        $colleague = $this->userWithRole($tenant, 'Colleague', []);

        // A concurrent call inserts the same token between our lookup and our
        // insert, exactly once.
        $raced = false;
        DeviceToken::creating(function (DeviceToken $device) use (&$raced, $tenant): void {
            if ($raced) {
                return;
            }
            $raced = true;
            DeviceToken::withoutGlobalScopes()->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $tenant['org']->id,
                'user_id' => $tenant['admin']->id,
                'platform' => 'android',
                'token' => $device->token,
                'created_at' => now(),
                'last_registered_at' => now(),
            ]);
        });

        $this->signedInAs($colleague)->postJson('/api/v1/notifications/devices', [
            'token' => 'token-raced', 'platform' => 'android',
        ])->assertOk();

        $devices = $this->inTenant($tenant['org'], fn () => DeviceToken::query()->get());
        $this->assertCount(1, $devices);
        $this->assertSame($colleague->id, $devices->first()->user_id);
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

    /**
     * Renamed and inverted from
     * `test_an_ios_token_is_refused_while_there_is_no_apns_sender`, which was
     * true until ApnsSender existed and would otherwise have stayed green while
     * asserting the opposite of what the code now does.
     *
     * What survives the inversion is the reason the refusal existed: an iOS row
     * is only deliverable if the CLIENT registered for remote notifications
     * proper. Android measured that their plugin's iOS token is a PushKit VoIP
     * credential, which wakes an app for a CALL and cannot carry this — and the
     * endpoint cannot tell the two apart by looking. That guarantee now lives in
     * the Apple module's Swift half, not in a validation rule here.
     */
    public function test_an_ios_token_is_accepted_now_that_an_apns_sender_exists(): void
    {
        $tenant = $this->provisionTenant('Kappa');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/notifications/devices', [
                'token' => 'token-real-apns',
                'platform' => 'ios',
            ])->assertOk();

        $devices = $this->inTenant($tenant['org'], fn () => DeviceToken::query()->get());
        $this->assertCount(1, $devices);
        $this->assertSame('ios', $devices->first()->platform);
    }

    public function test_a_platform_with_no_sender_at_all_is_still_refused(): void
    {
        $tenant = $this->provisionTenant('Lambda');

        // The control that keeps the rule honest: the allowed list is DERIVED
        // from the senders that exist, so something with no sender must still
        // be refused. Without this, `platforms()` could start returning
        // anything and nothing would notice.
        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/notifications/devices', [
                'token' => 'token-from-a-toaster',
                'platform' => 'windows',
            ])->assertStatus(422);

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => DeviceToken::query()->count()));
    }

    /** An EC P-256 key, the shape Apple issues a .p8 in. */
    private function configureApns(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($key, $pem);

        $path = storage_path('framework/testing/apns-'.Str::uuid7().'.p8');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $pem);

        config([
            'services.apns.key_id' => '8C7774ZGVH',
            'services.apns.team_id' => '2CMZQR34RL',
            'services.apns.topic' => 'com.ezbizi.app',
            'services.apns.key' => $path,
            'services.apns.production' => true,
        ]);
    }

    /**
     * THE TRAP, and the only reason this test exists.
     *
     * `openssl_sign` on an EC key emits a DER SEQUENCE of two INTEGERs. It is a
     * valid ECDSA signature and `openssl_verify` confirms it against the same
     * key all day. JWS ES256 is not that — it is exactly 64 bytes, R then S,
     * each left-padded to 32. Hand Apple the DER and the answer is 403
     * InvalidProviderToken: a key error, for a key that was never wrong, which
     * sends somebody to regenerate the one thing that was fine.
     *
     * Asserting the LENGTH is what distinguishes the two. A DER signature for
     * P-256 is 70-72 bytes and varies run to run; 64 exactly is the JOSE form.
     */
    /**
     * Jose, 2026-10-21: every push reads Title / Subtitle / Description —
     * "EzBizi - <Company> - <Branch>", what happened, then its content. His
     * screenshot had no company or branch anywhere.
     */
    public function test_a_push_says_whose_it_is_what_happened_and_the_content(): void
    {
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
            self::APNS => Http::response('', 200),
        ]);
        $this->configureFcm();
        $this->configureApns();
        $tenant = $this->provisionTenant('Xi');
        $this->device($tenant, $tenant['admin']->id, 'token-android');
        $iphone = $this->device($tenant, $tenant['admin']->id, 'token-iphone');
        $this->inTenant($tenant['org'], fn () => $iphone->forceFill(['platform' => 'ios'])->save());

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New reply on "Live Chat"', body: 'Thank you', subject: $tenant['franchise'], source: 'system',
        ));

        $header = 'EzBizi - '.$tenant['company']->name.' - '.$tenant['franchise']->name;

        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send')
            && $request['message']['notification']['title'] === $header
            && $request['message']['notification']['body'] === "New reply on \"Live Chat\"\nThank you");
        Http::assertSent(fn ($request) => str_contains($request->url(), 'token-iphone')
            && $request['aps']['alert'] === ['title' => $header, 'subtitle' => 'New reply on "Live Chat"', 'body' => 'Thank you']);
    }

    public function test_the_provider_token_is_signed_in_jose_form_not_der(): void
    {
        Http::fake([self::APNS => Http::response('', 200)]);

        $this->configureApns();
        $tenant = $this->provisionTenant('Mu');
        $device = $this->device($tenant, $tenant['admin']->id, 'token-iphone');
        $this->inTenant($tenant['org'], fn () => $device->forceFill(['platform' => 'ios'])->save());

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead', subject: $tenant['org'], source: 'automation',
        ));

        Http::assertSent(function ($request) {
            $this->assertSame('com.ezbizi.app', $request->header('apns-topic')[0]);

            $jwt = str_replace('bearer ', '', $request->header('authorization')[0]);
            [$header, , $signature] = explode('.', $jwt);

            $this->assertSame('ES256', json_decode(base64_decode(strtr($header, '-_', '+/')), true)['alg']);

            $raw = base64_decode(strtr($signature, '-_', '+/').str_repeat('=', (4 - strlen($signature) % 4) % 4));
            $this->assertSame(64, strlen($raw), 'ES256 is raw R||S, not DER');

            return true;
        });
    }

    public function test_apple_saying_gone_deletes_the_token(): void
    {
        // 410, not 404. FCM says 404/UNREGISTERED and Apple says 410/Gone for
        // the same thing; a sender checking only for 404 would keep every dead
        // iPhone token forever while looking like it pruned them.
        Http::fake([self::APNS => Http::response(['reason' => 'Unregistered'], 410)]);

        $this->configureApns();
        $tenant = $this->provisionTenant('Nu');
        $device = $this->device($tenant, $tenant['admin']->id, 'token-deleted-app');
        $this->inTenant($tenant['org'], fn () => $device->forceFill(['platform' => 'ios'])->save());

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead', subject: $tenant['org'], source: 'automation',
        ));

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => DeviceToken::query()->count()));
    }

    public function test_a_bad_device_token_is_kept_because_it_is_usually_the_wrong_environment(): void
    {
        // The control for the test above, and a real distinction: 400
        // BadDeviceToken usually means the token was minted for the other APNs
        // environment. Deleting on it would throw away a good token every time
        // somebody pointed a sandbox build at production.
        Http::fake([self::APNS => Http::response(['reason' => 'BadDeviceToken'], 400)]);

        $this->configureApns();
        $tenant = $this->provisionTenant('Xi');
        $device = $this->device($tenant, $tenant['admin']->id, 'token-wrong-env');
        $this->inTenant($tenant['org'], fn () => $device->forceFill(['platform' => 'ios'])->save());

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'New Live Chat Lead', subject: $tenant['org'], source: 'automation',
        ));

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
