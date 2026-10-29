<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A business connects its OWN WhatsApp number - Meta's Embedded Signup.
 *
 * Same Meta app and the same code exchange as FacebookOAuth, but the dialog
 * is driven by a Facebook Login for Business CONFIGURATION rather than a scope
 * list: `config_id` carries whatsapp_business_management and _messaging, and
 * the token it mints is a business integration system user token that does
 * not expire. Passing `scope` as well makes Meta treat it as an ordinary
 * login, which is why there is none here.
 *
 * Each phone number the business shared becomes one connection, keyed by its
 * phone_number_id - the id the webhook names and every send is addressed to.
 * Onboarding it (subscribe the WABA, register the number, write the
 * whatsapp_numbers row that routes it to this tenant) is OnboardWhatsAppNumber,
 * queued from the callback: three round trips per number is not something the
 * consent window should wait on.
 */
final class WhatsAppOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/whatsapp/callback';

    private const TIMEOUT = 15;

    public function provider(): SocialProvider
    {
        return SocialProvider::WhatsApp;
    }

    public function routeKey(): string
    {
        return 'whatsapp';
    }

    /** Named for the record only: the configuration grants them, not the URL. */
    public function scopes(): array
    {
        return ['whatsapp_business_management', 'whatsapp_business_messaging'];
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function requiresRefreshToken(): bool
    {
        return false;
    }

    public function notReadyBecause(): ?string
    {
        return MetaApp::notReadyBecause();
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.meta.whatsapp_redirect'),
            self::CALLBACK_PATH,
            'WhatsApp',
        );
    }

    /**
     * Embedded Signup's own flow (business, WABA, number). The
     * whatsapp_business_app_onboarding featureType ("coexistence", 2026-10-28)
     * offers to connect a number that stays in the WhatsApp Business app.
     */
    public const EXTRAS = ['featureType' => 'whatsapp_business_app_onboarding', 'sessionInfoVersion' => '3'];

    public const LAUNCH_PATH = '/api/v1/social/whatsapp/launch';

    /**
     * Our own page, which opens Meta through FB.login: a dialog/oauth link
     * drops `extras`, so the coexistence choice never showed (2026-10-29).
     */
    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return substr($this->redirectUri(), 0, -strlen(self::CALLBACK_PATH)).self::LAUNCH_PATH.'?state='.rawurlencode($state);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        $body = $this->get('https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/oauth/access_token', [
            'client_id' => MetaApp::id(),
            'client_secret' => MetaApp::secret(),
            // No redirect_uri: a code from FB.login is bound to none.
            'code' => $code,
        ]);

        $token = $body['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw SocialOAuthRefused::exchangeFailed('WhatsApp');
        }

        // No fb_exchange_token step: a system user token from this
        // configuration is already non-expiring, and exchanging it is refused.
        return new SocialOAuthGrant(
            accessToken: $token,
            scopes: $this->scopes(),
            expiresIn: is_numeric($body['expires_in'] ?? null) && (int) $body['expires_in'] > 0
                ? (int) $body['expires_in'] : null,
        );
    }

    /**
     * What the business shared: its WABAs from debug_token's granular scopes,
     * then every number in each.
     */
    public function identify(SocialOAuthGrant $grant): array
    {
        $graph = 'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION;

        $debug = $this->get($graph.'/debug_token', [
            'input_token' => $grant->accessToken,
            'access_token' => MetaApp::id().'|'.MetaApp::secret(),
        ]);

        $wabas = [];

        foreach ((array) ($debug['data']['granular_scopes'] ?? []) as $scope) {
            if (is_array($scope) && ($scope['scope'] ?? null) === 'whatsapp_business_management') {
                foreach ((array) ($scope['target_ids'] ?? []) as $id) {
                    $wabas[(string) $id] = true;
                }
            }
        }

        $accounts = [];

        foreach (array_keys($wabas) as $waba) {
            $numbers = $this->get($graph.'/'.$waba.'/phone_numbers', [
                'fields' => 'id,display_phone_number,verified_name,code_verification_status,status',
                'access_token' => $grant->accessToken,
            ]);

            foreach ((array) ($numbers['data'] ?? []) as $row) {
                $id = is_array($row) ? trim((string) ($row['id'] ?? '')) : '';

                if ($id === '') {
                    continue;
                }

                $display = trim((string) ($row['display_phone_number'] ?? ''));
                $name = trim((string) ($row['verified_name'] ?? ''));

                $accounts[] = new SocialAccount(
                    remoteId: $id,
                    displayName: $name !== '' && $display !== '' ? $name.' ('.$display.')' : ($name ?: $display ?: $id),
                    handle: $display !== '' ? $display : null,
                    wabaId: (string) $waba,
                );
            }
        }

        if ($accounts === []) {
            throw SocialOAuthRefused::unidentified(
                'WhatsApp',
                'No WhatsApp number was shared, so nothing has been connected. Finish the signup '
                .'and choose a number, then try again.',
            );
        }

        return $accounts;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->get($url, $query);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('WhatsApp');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed('WhatsApp');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
