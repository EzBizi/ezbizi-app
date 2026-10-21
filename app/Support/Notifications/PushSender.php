<?php

namespace App\Support\Notifications;

use App\Models\DeviceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Waking a handset that is not running EzBizi (plan §12).
 *
 * The bell reaches somebody looking at the app and the email reaches somebody
 * reading their mail. This is the only one of the three that reaches a phone in
 * a pocket, and it is the only one that needs an address per DEVICE rather than
 * per person — see DeviceToken.
 *
 * ── Plain HTTP, no SDK ───────────────────────────────────────────
 *
 * EzBizi's standing preference, and firebase/php-jwt plus the Google API client
 * would be two dependencies and a transitive tree to do what forty lines of
 * OpenSSL and one POST already do. The FCM v1 API is a JSON endpoint with an
 * OAuth bearer token; nothing about it is easier through a wrapper.
 *
 * ── The duplication in here, named rather than hidden ────────────
 *
 * `GoogleWorkspace::signJwt` already signs a service-account assertion, and
 * this signs a second one. They are not the same request: Workspace uses
 * domain-wide delegation and carries a `sub` claim impersonating a mailbox
 * owner, and this is a plain service-account grant with no subject at all. The
 * shared part is about fifteen lines of RFC 7519, and extracting it would mean
 * refactoring an eight-hundred-line file another module owns, in a shared tree,
 * to save them. If a third caller ever appears that is the moment to lift all
 * three out together — two is not yet a pattern.
 */
class PushSender
{
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TIMEOUT = 15;

    /**
     * The Android notification channel these arrive on.
     *
     * ── Why naming it matters, and it is not cosmetic ────────────────
     *
     * Without it FCM delivers on `fcm_fallback_notification_channel`, its own
     * generic default — measured on a real handset by the Android module, in
     * the notification shade, not inferred. On Android 8 and later the CHANNEL
     * is what a person sees in Settings and what they mute, so a fallback
     * channel means EzBizi notifications cannot be tuned separately from
     * anything else and appear under a name nobody chose. It is invisible until
     * somebody tries to turn one off, which is the worst moment to discover it.
     *
     * ── The string is duplicated across two languages, deliberately ──
     *
     * `client/lib/core/os_notifications.dart` creates a channel with this exact
     * id for LOCAL notifications. The two must match or a person ends up with
     * two EzBizi entries in Settings — one for notifications raised while the
     * app is open and one for those that wake it — which is worse than the
     * single generic entry this replaces.
     *
     * There is no shared source between a PHP sender and a Dart client, so the
     * duplication is real and the comment is the only thing holding them
     * together. Changing one means changing the other.
     *
     * ── Safe to send before the channel exists ───────────────────────
     *
     * FCM falls back to the manifest default, or to its own, for a channel the
     * app has not created. So this degrades to exactly today's behaviour rather
     * than to silence, and starts working the moment the client declares it.
     */
    private const ANDROID_CHANNEL = 'ezbizi_notifications';

    /**
     * The channel for what should interrupt: alerts, new leads, missed calls.
     *
     * IMPORTANCE_HIGH (heads-up banner and sound) where the default channel is
     * IMPORTANCE_DEFAULT. Jose, 2026-10-08, on the importance decision: "why
     * tomorrow, do it now" — HIGH for alerts, calls and new leads, DEFAULT for
     * the rest. A SEPARATE channel rather than raising the old one because
     * Android never lets an app raise a channel's importance once it exists,
     * and every handset that has run EzBizi already has the old one.
     *
     * Same duplication rule as above: MainActivity.kt creates this id natively
     * with these exact values. Change one, change both.
     */
    public const ANDROID_URGENT_CHANNEL = 'ezbizi_urgent';

    public function __construct(private readonly ApnsSender $apns) {}

    /**
     * The platforms a token can be REGISTERED for.
     *
     * Which senders EXIST, not which are configured — and the distinction is
     * load-bearing. A developer machine has no FCM credential and a production
     * box before the key lands has none either; if this answered "configured"
     * both would refuse Android registrations, and the tokens that should have
     * been accumulating against the day it works would simply be lost.
     * Registration asks "can this ever be delivered to", and sending asks "can
     * it be delivered to now".
     *
     * `ios` was absent until ApnsSender existed, and that absence had a reason
     * worth keeping in view: the Android module measured that the token their
     * plugin exposes on iOS is a PushKit VoIP token, a different credential
     * that wakes an app for a CALL and cannot carry a notification. So an iOS
     * row is only ever valid if the CLIENT registered for remote notifications
     * proper — the endpoint cannot tell the two apart by looking, and the Apple
     * module owns the Swift side that makes it the right one.
     *
     * @return string[]
     */
    public function platforms(): array
    {
        return ['android', 'ios'];
    }

    /**
     * Is there anything to send with?
     *
     * Asked rather than assumed because the credential is deliberately NOT in
     * the repository: it lives on the box and arrives through the environment,
     * so a developer machine and a server legitimately disagree about whether
     * this channel exists. A missing credential is a configuration state, not a
     * fault, and it must not throw from inside a queue worker.
     */
    public function configured(): bool
    {
        return $this->fcmConfigured() || $this->apns->configured();
    }

    private function fcmConfigured(): bool
    {
        return $this->projectId() !== null && $this->credentials() !== null;
    }

    /**
     * @param  Collection<int, DeviceToken>  $devices
     * @return int how many devices accepted the message
     */
    public function send(Collection $devices, string $title, ?string $body, bool $urgent = false, ?string $notificationId = null, ?string $subtitle = null): int
    {
        if ($devices->isEmpty()) {
            return 0;
        }

        /*
         * ROUTED BY PLATFORM, because the two services share a shape and
         * nothing else — different endpoint, auth scheme, signature algorithm
         * and dead-token signal. The dispatcher does not know either of them
         * exists; it asks for a notification to reach a set of handsets and
         * this decides how each one is reached.
         */
        [$apple, $google] = $devices->partition(fn (DeviceToken $device) => $device->platform === 'ios');

        $sent = $this->apns->send($apple, $title, $body, $notificationId, $subtitle);

        // Android has no subtitle line a data-less FCM notification can set, so
        // what happened leads the body (Jose, 2026-10-21: Title / Subtitle /
        // Description on every push).
        $body = implode("\n", array_filter([$subtitle, $body], fn ($line) => $line !== null && $line !== '')) ?: null;

        if ($google->isEmpty() || ! $this->fcmConfigured()) {
            return $sent;
        }

        $devices = $google;

        try {
            $accessToken = $this->accessToken();
        } catch (Throwable $exception) {
            // One failure for the whole batch: if the credential cannot mint a
            // token, every device in this dispatch fails for the same reason,
            // and logging it per device would bury the one line that explains
            // it under fifty copies.
            Log::warning('Push not sent: could not authenticate to FCM.', [
                'error' => $exception->getMessage(),
                'devices' => $devices->count(),
            ]);

            return $sent;
        }

        foreach ($devices as $device) {
            if ($this->deliver($device, $accessToken, $title, $body, $urgent, $notificationId)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * One device. True when FCM accepted it.
     *
     * ── Why a dead token is DELETED here rather than swept later ─────
     *
     * FCM answers `UNREGISTERED` (404) for a token whose app was uninstalled or
     * whose registration was replaced. That answer is the only reliable signal
     * a device is gone: a send to a dead token otherwise succeeds and delivers
     * nothing, so silence is not evidence. Acting on it at the moment it
     * arrives keeps the table honest without a sweep that would have to
     * re-derive what the API just said out loud.
     */
    private function deliver(DeviceToken $device, string $accessToken, string $title, ?string $body, bool $urgent = false, ?string $notificationId = null): bool
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withToken($accessToken)
                ->post(sprintf(self::FCM_ENDPOINT, $this->projectId()), [
                    'message' => array_filter([
                        'token' => $device->token,
                        'notification' => array_filter([
                            'title' => $title,
                            'body' => $body,
                        ]),
                        'android' => ['notification' => ['channel_id' => $urgent ? self::ANDROID_URGENT_CHANNEL : self::ANDROID_CHANNEL]],
                        // Which bell row a tap opens: the app routes it exactly as
                        // tapping that row (Bar, 2026-10-12). The KEY is dropped,
                        // not emptied, when there is no row: PHP's empty array
                        // encodes as JSON [] and FCM refuses `"data": []`, which
                        // would fail every push sent with the bell switched off.
                        'data' => $notificationId === null ? null : ['notification_id' => $notificationId],
                    ], fn ($value) => $value !== null),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Push not sent: FCM unreachable.', [
                'device_token_id' => $device->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 404 || $response->json('error.status') === 'NOT_FOUND') {
            $device->delete();

            return false;
        }

        Log::warning('Push refused by FCM.', [
            'device_token_id' => $device->getKey(),
            'status' => $response->status(),
            'reason' => $response->json('error.status'),
        ]);

        return false;
    }

    /**
     * A bearer token for FCM, cached just short of its own lifetime.
     *
     * Google issues these for an hour. Cached for fifty-five minutes so a token
     * is never used in the last minutes of its life, where a slow request could
     * arrive after it expired — and re-minting costs a signature and a round
     * trip that every notification in that hour would otherwise repeat.
     */
    private function accessToken(): string
    {
        return Cache::remember('notifications.fcm.access_token', now()->addMinutes(55), function (): string {
            $credentials = $this->credentials();

            if ($credentials === null) {
                throw new RuntimeException('No FCM service account is configured.');
            }

            $now = time();

            $assertion = $this->signJwt([
                'iss' => $credentials['client_email'],
                'aud' => self::TOKEN_ENDPOINT,
                'scope' => self::SCOPE,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key']);

            $response = Http::timeout(self::TIMEOUT)->asForm()->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            $token = $response->json('access_token');

            if (! $response->successful() || ! is_string($token)) {
                throw new RuntimeException(sprintf(
                    'FCM refused the service account (HTTP %d): %s',
                    $response->status(),
                    (string) $response->json('error_description', 'no reason given'),
                ));
            }

            return $token;
        });
    }

    private function signJwt(array $claims, string $privateKey): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signing = $header.'.'.$body;
        $signature = '';

        if (openssl_sign($signing, $signature, $privateKey, OPENSSL_ALGO_SHA256) !== true || $signature === '') {
            throw new RuntimeException(
                'EzBizi could not sign a push request. The FCM service account private key is '
                .'present but unusable — most often a key that lost its line breaks on its way '
                .'into the environment.'
            );
        }

        return $signing.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function projectId(): ?string
    {
        $id = config('services.fcm.project_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The service account, read from a file on the box.
     *
     * A PATH rather than the JSON inlined into the environment, because the
     * private key inside it is multi-line and a .env carrying one is how a key
     * arrives with its newlines eaten — the failure signJwt's message names.
     *
     * @return array{client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $path = config('services.fcm.credentials');

        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        try {
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $email = $json['client_email'] ?? null;
        $key = $json['private_key'] ?? null;

        return is_string($email) && is_string($key) && $email !== '' && $key !== ''
            ? ['client_email' => $email, 'private_key' => $key]
            : null;
    }
}
