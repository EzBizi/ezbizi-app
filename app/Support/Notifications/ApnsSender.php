<?php

namespace App\Support\Notifications;

use App\Models\DeviceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Apple half of push (plan §12) — see PushSender for the FCM half.
 *
 * Deliberately a separate class rather than a branch inside that one. The two
 * share a shape and nothing else: different endpoint, different auth scheme,
 * different signature algorithm, different dead-token signal, and a topic
 * header Apple requires that Google has no equivalent of. A single class with
 * two of everything reads as one thing that can be understood at once, and it
 * is not.
 *
 * ── Three traps, all recorded before the first line was written ──
 *
 * 1. ES256, NOT RS256 — and `openssl_sign` emits DER where JOSE wants a raw
 *    64-byte R‖S pair. A DER signature verifies perfectly against the same key
 *    locally and is rejected by Apple with a 403, which is the worst possible
 *    failure to debug: the instrument is healthy and aimed one field away. See
 *    [joseSignature].
 *
 * 2. HTTP/2 IS MANDATORY. Apple will not answer HTTP/1.1 at all. Measured
 *    available in this PHP's curl (8.20.0, CURL_VERSION_HTTP2 set); it has to
 *    be true on the production box as well, and [unsupportedBecause] says so
 *    out loud rather than letting it fail as a timeout.
 *
 * 3. `apns-topic` IS THE BUNDLE ID, `com.ezbizi.app` — measured by the Apple
 *    module off the shipped artefact, not the Kotlin-style namespace that looks
 *    like it. Getting it wrong returns 400 BadDeviceToken, which reads like a
 *    bad token rather than a bad header.
 */
class ApnsSender
{
    private const PRODUCTION = 'https://api.push.apple.com/3/device/';

    private const SANDBOX = 'https://api.sandbox.push.apple.com/3/device/';

    private const TIMEOUT = 15;

    public function configured(): bool
    {
        return $this->settings() !== null && $this->unsupportedBecause() === null;
    }

    /**
     * Why this platform cannot send here, or null when it can.
     *
     * A sentence rather than a boolean because the one thing that can be wrong
     * outside configuration — a curl without HTTP/2 — produces a failure that
     * looks like a network problem, and somebody would spend an afternoon on
     * Apple's status page. Naming it at the point of refusal is the difference.
     */
    public function unsupportedBecause(): ?string
    {
        return (curl_version()['features'] & CURL_VERSION_HTTP2) === 0
            ? 'This server\'s curl was built without HTTP/2, which APNs requires and will '
                .'not negotiate down from. Nothing can be pushed to an iPhone until curl is '
                .'rebuilt with nghttp2.'
            : null;
    }

    /**
     * @param  Collection<int, DeviceToken>  $devices
     * @return int how many devices accepted the message
     */
    public function send(Collection $devices, string $title, ?string $body, ?string $notificationId = null): int
    {
        if ($devices->isEmpty() || ! $this->configured()) {
            return 0;
        }

        try {
            $jwt = $this->providerToken();
        } catch (RuntimeException $exception) {
            Log::warning('Push not sent: could not sign an APNs provider token.', [
                'error' => $exception->getMessage(),
                'devices' => $devices->count(),
            ]);

            return 0;
        }

        $sent = 0;

        foreach ($devices as $device) {
            if ($this->deliver($device, $jwt, $title, $body, $notificationId)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * One handset. True when Apple accepted it.
     *
     * ── 410 is the dead-token signal, and it is not 404 ──────────────
     *
     * FCM answers 404/UNREGISTERED; Apple answers 410 Gone with reason
     * `Unregistered`. Same meaning, different number, and a sender that checked
     * for 404 here would keep every dead iPhone token forever while looking
     * like it pruned them. 400 `BadDeviceToken` is deliberately NOT treated the
     * same way: it usually means the token was minted for the other
     * environment, and deleting on it would throw away a good token every time
     * somebody pointed a sandbox build at production.
     */
    private function deliver(DeviceToken $device, string $jwt, string $title, ?string $body, ?string $notificationId = null): bool
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions(['version' => 2.0])
                ->withHeaders([
                    'authorization' => 'bearer '.$jwt,
                    'apns-topic' => $this->settings()['topic'],
                    'apns-push-type' => 'alert',
                    'apns-priority' => '10',
                ])
                ->post($this->endpoint().$device->token, array_filter([
                    'aps' => [
                        'alert' => array_filter(['title' => $title, 'body' => $body]),
                        'sound' => 'default',
                    ],
                    // Which bell row a tap opens — a custom key beside `aps`,
                    // which is where APNs puts app data (Bar, 2026-10-12).
                    'notification_id' => $notificationId,
                ]));
        } catch (ConnectionException $exception) {
            Log::warning('Push not sent: APNs unreachable.', [
                'device_token_id' => $device->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 410 || $response->json('reason') === 'Unregistered') {
            $device->delete();

            return false;
        }

        /*
         * THE KEY ID AND TOPIC ARE IN HERE BECAUSE THEY ARE WHAT DISTINGUISHES
         * THE TWO REFUSALS THAT LOOK LIKE A BROKEN SIGNATURE.
         *
         * 403 InvalidProviderToken reads as "the signature is wrong", and the
         * first instinct is the ES256 DER-versus-raw trap in joseSignature.
         * But a JWT signed perfectly with the right key and stamped with the
         * WRONG `kid` fails in exactly the same way — and the credentials
         * folder holds two .p8 files whose names differ by eight characters,
         * one of which (27YW9B9D6Y) authenticates to App Store Connect rather
         * than to APNs. The Apple module named that trap; this is it written
         * down where somebody debugging at speed will actually see it, rather
         * than in a message from three weeks earlier.
         *
         * 400 BadDeviceToken has the same shape one level over: usually the
         * token was minted for the other environment, but a wrong `apns-topic`
         * produces it too, and the topic is not otherwise visible from a log.
         *
         * Neither value is a secret. A Key ID is an identifier and travels in
         * the JWT header in clear; the topic is the bundle id, which is on
         * every build.
         */
        Log::warning('Push refused by APNs.', [
            'device_token_id' => $device->getKey(),
            'status' => $response->status(),
            'reason' => $response->json('reason'),
            'key_id' => $this->settings()['key_id'] ?? null,
            'topic' => $this->settings()['topic'] ?? null,
        ]);

        return false;
    }

    /**
     * The provider token, cached for well under Apple's own limit.
     *
     * Apple accepts a token for an hour and REFUSES a provider that mints them
     * too often — TooManyProviderTokenUpdates, which is a ban rather than a
     * warning. Fifty minutes keeps one token in use for most of its life and
     * nowhere near the once-per-twenty-minutes floor.
     */
    private function providerToken(): string
    {
        return Cache::remember('notifications.apns.provider_token', now()->addMinutes(50), function (): string {
            $settings = $this->settings();

            if ($settings === null) {
                throw new RuntimeException('No APNs key is configured.');
            }

            $header = $this->base64Url(json_encode(
                ['alg' => 'ES256', 'kid' => $settings['key_id'], 'typ' => 'JWT'],
                JSON_THROW_ON_ERROR,
            ));

            $claims = $this->base64Url(json_encode(
                ['iss' => $settings['team_id'], 'iat' => time()],
                JSON_THROW_ON_ERROR,
            ));

            return $header.'.'.$claims.'.'.$this->base64Url(
                $this->joseSignature($header.'.'.$claims, $settings['key'])
            );
        });
    }

    /**
     * Sign, and convert DER to the raw R‖S pair JOSE requires.
     *
     * THIS IS THE TRAP. `openssl_sign` with an EC key produces a DER SEQUENCE
     * of two INTEGERs. It is a valid ECDSA signature and `openssl_verify` will
     * confirm it against the same key all day. JWS ES256 is not that: it is
     * exactly 64 bytes, R then S, each left-padded to 32. Hand Apple the DER
     * and you get 403 InvalidProviderToken — a key error for a key that is
     * perfectly good, which sends somebody to regenerate the one thing that was
     * never wrong.
     *
     * The unpicking is: skip the SEQUENCE header, read each INTEGER, drop the
     * leading zero DER adds when the high bit would make the value negative,
     * and left-pad back to 32.
     */
    private function joseSignature(string $signing, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new RuntimeException(
                'The APNs key could not be read. It must be the .p8 exactly as Apple issued '
                .'it, PKCS#8 with its BEGIN PRIVATE KEY lines intact.'
            );
        }

        $der = '';

        if (openssl_sign($signing, $der, $key, OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('The APNs key is present but would not sign.');
        }

        $offset = 2;                                  // SEQUENCE tag + length
        $offset += 1;                                 // INTEGER tag for R
        $rLength = ord($der[$offset++]);
        $r = ltrim(substr($der, $offset, $rLength), "\x00");
        $offset += $rLength + 1;                      // past R, past INTEGER tag for S
        $sLength = ord($der[$offset++]);
        $s = ltrim(substr($der, $offset, $sLength), "\x00");

        return str_pad($r, 32, "\x00", STR_PAD_LEFT).str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    private function endpoint(): string
    {
        return config('services.apns.production') ? self::PRODUCTION : self::SANDBOX;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array{key_id: string, team_id: string, topic: string, key: string}|null
     */
    private function settings(): ?array
    {
        $keyId = config('services.apns.key_id');
        $teamId = config('services.apns.team_id');
        $topic = config('services.apns.topic');
        $path = config('services.apns.key');

        if (! is_string($keyId) || ! is_string($teamId) || ! is_string($topic)
            || ! is_string($path) || $keyId === '' || $teamId === '' || $topic === ''
            || $path === '' || ! is_readable($path)) {
            return null;
        }

        $key = (string) file_get_contents($path);

        return $key === '' ? null : [
            'key_id' => $keyId,
            'team_id' => $teamId,
            'topic' => $topic,
            'key' => $key,
        ];
    }
}
