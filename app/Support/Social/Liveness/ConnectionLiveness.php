<?php

namespace App\Support\Social\Liveness;

use App\Models\SocialConnection;
use App\Support\Social\BlueskySession;
use App\Support\Social\ConnectionSetup;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\GoogleToken;
use App\Support\Social\Publishing\BlueskyPublisher;
use App\Support\Social\Publishing\LinkedInPublisher;
use App\Support\Social\Publishing\PinterestPublisher;
use App\Support\Social\Publishing\TikTokPublisher;
use App\Support\Social\Publishing\XPublisher;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackClient;
use App\Support\Workiz\WorkizV2;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The cheapest read-only call per provider that proves a stored token still
 * works, for `ezbizi:check-connection-health` (Jose, 2026-11-03).
 *
 * A map rather than an interface on every publisher: most of these providers
 * have no class of their own to hang it on (Airtable, Workiz, RingCentral,
 * Telegram and Mailchimp are pasted keys), and one file shows every check
 * side by side.
 *
 * Renewal goes through the code that already renews for real work — the
 * publishers' token(), GoogleToken::forConnection(), ThumbtackClient — because
 * those hold the per-connection locks and the rotate-and-fan-out rules
 * (SocialConnection::connect() says why a second copy would be dangerous).
 * They already mark a row broken on a definitive refusal, so a renewal that
 * throws is read back from the row: broken means refused, anything else means
 * no verdict.
 *
 * NOT CHECKED, because nothing cheap and read-only exists:
 *  - workiz_web: a signed-in browser session; "checking" it is a full login.
 *  - ekomi: a shop id and password; its only reads are the review feeds the
 *    sync already pulls, and they say nothing distinct about the login.
 *  - any provider added later until it is given an entry here.
 */
final class ConnectionLiveness
{
    private const TIMEOUT = 15;

    /** Providers this checks; everything else is left untouched. */
    public static function checks(string $provider): bool
    {
        return array_key_exists($provider, self::map());
    }

    public function check(SocialConnection $c): Liveness
    {
        $check = self::map()[$c->provider] ?? null;
        if ($check === null) {
            return Liveness::unknown('No liveness check for this provider.');
        }

        try {
            $result = $check($c);
        } catch (ConnectionException) {
            return Liveness::unknown('The provider did not answer.');
        }

        // A live token whose setup is gone queues that setup again (ConnectionSetup).
        if ($result->verdict === Liveness::ALIVE) {
            ConnectionSetup::readBack($c);
        }

        return $result;
    }

    /** @return array<string, Closure(SocialConnection): Liveness> */
    private static function map(): array
    {
        $meta = fn (SocialConnection $c) => self::meta($c);

        return [
            SocialProvider::TikTok->value => fn (SocialConnection $c) => self::renewThenRead($c, 'TikTok',
                fn () => app(TikTokPublisher::class)->token($c),
                fn (string $t) => Http::withToken($t)->timeout(self::TIMEOUT)
                    ->get('https://open.tiktokapis.com/v2/user/info/', ['fields' => 'open_id'])),
            SocialProvider::X->value => fn (SocialConnection $c) => self::renewThenRead($c, 'X',
                fn () => app(XPublisher::class)->token($c),
                fn (string $t) => Http::withToken($t)->timeout(self::TIMEOUT)->get('https://api.x.com/2/users/me')),
            SocialProvider::LinkedIn->value => fn (SocialConnection $c) => self::renewThenRead($c, 'LinkedIn',
                fn () => app(LinkedInPublisher::class)->token($c),
                fn (string $t) => Http::withToken($t)->timeout(self::TIMEOUT)->get('https://api.linkedin.com/v2/userinfo')),
            SocialProvider::Bluesky->value => fn (SocialConnection $c) => self::renewThenRead($c, 'Bluesky',
                fn () => app(BlueskyPublisher::class)->token($c),
                fn (string $t) => Http::withToken($t)->acceptJson()->timeout(self::TIMEOUT)
                    ->get(BlueskySession::PDS.'/xrpc/com.atproto.server.getSession')),
            SocialProvider::Pinterest->value => fn (SocialConnection $c) => self::renewThenRead($c, 'Pinterest',
                fn () => app(PinterestPublisher::class)->token($c),
                fn (string $t) => Http::withToken($t)->timeout(self::TIMEOUT)->get('https://api.pinterest.com/v5/user_account')),

            // A Google refresh that works is the proof; forConnection() marks
            // the row broken itself on invalid_grant and on nothing else.
            SocialProvider::YouTube->value => fn (SocialConnection $c) => self::google($c, 'youtube'),
            SocialProvider::GoogleBusinessProfile->value => fn (SocialConnection $c) => self::google($c, 'business_profile'),
            SocialProvider::GoogleAds->value => fn (SocialConnection $c) => self::google($c, 'google_ads'),

            SocialProvider::FacebookPage->value => $meta,
            SocialProvider::InstagramProfessional->value => $meta,
            SocialProvider::Threads->value => $meta,
            SocialProvider::FacebookAds->value => $meta,
            SocialProvider::WhatsApp->value => $meta,

            SocialProvider::Thumbtack->value => fn (SocialConnection $c) => self::thumbtack($c),

            SocialProvider::Airtable->value => fn (SocialConnection $c) => self::renewThenRead($c, 'Airtable',
                fn () => self::stored($c, 'api_token'),
                fn (string $t) => Http::withToken($t)->timeout(self::TIMEOUT)->get('https://api.airtable.com/v0/meta/whoami')),
            SocialProvider::Workiz->value => fn (SocialConnection $c) => self::workiz($c),
            SocialProvider::RingCentral->value => fn (SocialConnection $c) => self::ringCentral($c),
            SocialProvider::Telegram->value => fn (SocialConnection $c) => self::renewThenRead($c, 'Telegram',
                fn () => self::stored($c, 'bot_token'),
                fn (string $t) => Http::timeout(self::TIMEOUT)->get('https://api.telegram.org/bot'.$t.'/getMe')),
            SocialProvider::Mailchimp->value => fn (SocialConnection $c) => self::mailchimp($c),
        ];
    }

    /**
     * Renew (when the provider renews) with the existing code, then one read.
     *
     * @param  Closure(): ?string  $token
     * @param  Closure(string): Response  $read
     */
    private static function renewThenRead(SocialConnection $c, string $network, Closure $token, Closure $read): Liveness
    {
        try {
            $access = $token();
        } catch (ConnectionException $e) {
            throw $e;
        } catch (Throwable) {
            return self::fromRow($c);
        }

        if (! is_string($access) || $access === '') {
            return Liveness::refused(self::sentence($network));
        }

        return self::verdict($read($access), $network);
    }

    private static function google(SocialConnection $c, string $service): Liveness
    {
        if (GoogleToken::paused($c)) {
            return Liveness::unknown('Paused in Settings.');
        }

        try {
            GoogleToken::forConnection($c, $service);
        } catch (Throwable) {
            return self::fromRow($c);
        }

        return Liveness::alive();
    }

    private static function meta(SocialConnection $c): Liveness
    {
        $token = self::stored($c, 'access_token');
        if ($token === null) {
            return Liveness::refused(self::sentence($c->providerLabel()));
        }

        [$base, $id] = match ($c->provider) {
            SocialProvider::InstagramProfessional->value => ['https://graph.instagram.com/v21.0/', 'me'],
            SocialProvider::Threads->value => ['https://graph.threads.net/v1.0/', 'me'],
            SocialProvider::FacebookAds->value => ['https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/', 'me'],
            default => ['https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/', (string) $c->remote_id],
        };

        $r = Http::timeout(self::TIMEOUT)->get($base.$id, ['fields' => 'id', 'access_token' => $token]);

        // Code 190 is Meta's "this token is invalid or revoked". A permission
        // loss (code 10, 200...) is ezbizi:meta-access-check's to judge.
        if ($r->clientError() && (int) $r->json('error.code') === 190) {
            return Liveness::refused(self::sentence($c->providerLabel()));
        }

        return self::verdict($r, $c->providerLabel());
    }

    private static function thumbtack(SocialConnection $c): Liveness
    {
        if ($c->refreshToken() === null) {
            return Liveness::unknown('No refresh token stored.');
        }

        try {
            (new ThumbtackClient($c))->keepAlive();
        } catch (Throwable) {
            return self::fromRow($c);
        }

        return Liveness::alive();
    }

    private static function workiz(SocialConnection $c): Liveness
    {
        if (WorkizV2::uses($c)) {
            $read = WorkizV2::send($c, 'GET', '/users', ['page' => 1, 'pageSize' => 1], self::TIMEOUT);
            if ($read['refused'] === null) {
                return Liveness::alive();
            }

            return $read['status'] === 401 ? Liveness::refused(self::sentence('Workiz')) : Liveness::unknown($read['refused']);
        }

        return self::renewThenRead($c, 'Workiz', fn () => self::stored($c, 'api_token'),
            fn (string $t) => Http::acceptJson()->timeout(self::TIMEOUT)->get('https://api.workiz.com/api/v1/'.$t.'/team/all/'));
    }

    private static function ringCentral(SocialConnection $c): Liveness
    {
        $cred = (array) ($c->credentials ?? []);
        $server = (string) ($cred['server'] ?? 'https://platform.ringcentral.com');

        $r = Http::asForm()->timeout(self::TIMEOUT)
            ->withBasicAuth((string) ($cred['client_id'] ?? ''), (string) ($cred['client_secret'] ?? ''))
            ->post($server.'/restapi/oauth/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => (string) ($cred['jwt'] ?? ''),
            ]);

        // invalid_grant: the JWT was revoked; invalid_client: the app's secret was.
        if (in_array($r->json('error'), ['invalid_grant', 'invalid_client'], true)) {
            return Liveness::refused(self::sentence('RingCentral'));
        }

        return self::verdict($r, 'RingCentral');
    }

    private static function mailchimp(SocialConnection $c): Liveness
    {
        $key = self::stored($c, 'api_key');
        $dc = $key !== null && str_contains($key, '-') ? substr($key, strrpos($key, '-') + 1) : '';
        if ($dc === '') {
            return Liveness::unknown('The stored key names no datacentre.');
        }

        return self::verdict(Http::withBasicAuth('anystring', (string) $key)->acceptJson()->timeout(self::TIMEOUT)
            ->get('https://'.$dc.'.api.mailchimp.com/3.0/ping'), 'Mailchimp');
    }

    /** 2xx alive; 401 refused; anything else (429, 5xx, a 400 of ours) no verdict. */
    private static function verdict(Response $r, string $network): Liveness
    {
        if ($r->successful()) {
            return Liveness::alive();
        }

        return $r->status() === 401
            ? Liveness::refused(self::sentence($network))
            : Liveness::unknown($network.' answered HTTP '.$r->status().'.');
    }

    /** A renewal threw: the renewal code marks broken only on a definitive refusal. */
    private static function fromRow(SocialConnection $c): Liveness
    {
        $c->refresh();

        return $c->status === SocialConnection::STATUS_BROKEN
            ? Liveness::refused((string) ($c->status_reason ?: self::sentence($c->providerLabel())))
            : Liveness::unknown('The renewal failed without a refusal.');
    }

    private static function stored(SocialConnection $c, string $key): ?string
    {
        $value = ((array) ($c->credentials ?? []))[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function sentence(string $network): string
    {
        return $network.' no longer accepts this connection. It needs connecting again.';
    }
}
