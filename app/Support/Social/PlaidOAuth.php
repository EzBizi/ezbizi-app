<?php

namespace App\Support\Social;

use App\Support\Financials\Plaid\PlaidClient;
use App\Support\Financials\Plaid\PlaidError;
use App\Support\Financials\Plaid\PlaidRefused;
use Illuminate\Support\Facades\Cache;

/**
 * Connect a bank through Plaid Hosted Link (Jose, 2026-11-24: Plaid approved).
 *
 * Plaid is not OAuth, but Hosted Link has the same shape, so it rides the
 * same sign-in rows, Connect card and callback as every other provider:
 *
 *  - authorizeUrl: a link_token for Transactions with a Hosted Link page
 *    whose completion_redirect_uri is our callback carrying the sign-in's
 *    state (as `state` and as `code`). The link_token waits in the cache
 *    under the state's hash. Plaid's page handles the banks' own OAuth; the
 *    same URL works in a browser on web, Windows, iOS and Android, so no
 *    native SDK ships.
 *  - exchange: Plaid calls the completion URI on success AND on exit, so the
 *    callback proves nothing by itself. The server asks Plaid (/link/token/get,
 *    with EzBizi's secret) what the session produced, and exchanges the
 *    public_token for the Item's access_token. Nothing from the browser is
 *    trusted beyond the state that names the sign-in.
 *  - identify: the bank, named as Plaid names it; the Item id is the remote id.
 *
 * The access_token never expires and has no refresh token. A bank that wants
 * the person to sign in again is fixed by update mode (BankController::reconnect).
 */
final class PlaidOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/plaid/callback';

    /** Where Plaid returns after a reconnect (update mode); SocialAuthController::plaidRelinked. */
    public const RELINKED_PATH = '/api/v1/social/plaid/relinked';

    public const WEBHOOK_PATH = '/api/v1/integrations/plaid/webhook';

    /** Two years: what Plaid will read back on the first sync. */
    public const DAYS_REQUESTED = 730;

    public function __construct(private readonly PlaidClient $client) {}

    public function provider(): SocialProvider
    {
        return SocialProvider::Plaid;
    }

    public function routeKey(): string
    {
        return 'plaid';
    }

    public function scopes(): array
    {
        return ['transactions'];
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
        return PlaidClient::ready() ? null
            : 'EzBizi has no Plaid keys set up on this installation yet, so a bank cannot be connected. '
              .'Nothing is wrong with your bank — the missing piece is at EzBizi\'s end.';
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        $created = $this->linkToken([
            'products' => ['transactions'],
            'transactions' => ['days_requested' => self::DAYS_REQUESTED],
        ], $state);
        Cache::put(self::cacheKey($state), $created['link_token'], now()->addMinutes(SocialOAuthSignIns::MINUTES + 5));

        return $created['hosted_link_url'];
    }

    /**
     * A Hosted Link session: a new bank ($extra has products), or update mode
     * for one already connected ($extra has access_token, no products).
     *
     * @param  array<string, mixed>  $extra
     * @return array{link_token: string, hosted_link_url: string}
     *
     * @throws SocialOAuthRefused
     */
    public function linkToken(array $extra, string $state, string $callbackPath = self::CALLBACK_PATH): array
    {
        try {
            $body = $this->client->post('link/token/create', $extra + [
                'client_name' => 'EzBizi',
                'language' => 'en',
                'country_codes' => ['US'],
                // Stable per sign-in, carries nothing about the person.
                'user' => ['client_user_id' => 'ezbizi-'.substr(hash('sha256', $state), 0, 32)],
                'webhook' => self::url(self::WEBHOOK_PATH),
                'hosted_link' => [
                    'completion_redirect_uri' => self::url($callbackPath).'?'.http_build_query(['state' => $state, 'code' => $state]),
                    'url_lifetime_seconds' => SocialOAuthSignIns::MINUTES * 60,
                ],
            ]);
        } catch (PlaidError|PlaidRefused $e) {
            report(new \RuntimeException('Plaid refused link/token/create: '.($e->plaidCode ?? '')));
            throw SocialOAuthRefused::notConfigured('Plaid', 'Plaid would not open a bank sign-in for EzBizi.');
        }

        $token = $body['link_token'] ?? null;
        $url = $body['hosted_link_url'] ?? null;
        if (! is_string($token) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw SocialOAuthRefused::notConfigured('Plaid', 'Plaid answered without a sign-in page.');
        }

        return ['link_token' => $token, 'hosted_link_url' => $url];
    }

    public static function cacheKey(string $state): string
    {
        return 'plaid-link:'.hash('sha256', $state);
    }

    /**
     * What the Hosted Link session produced. Null when the person left
     * without connecting a bank.
     *
     * @return array{public_token: string, institution: ?string}|null
     *
     * @throws SocialOAuthRefused
     */
    public function sessionResult(string $linkToken): ?array
    {
        try {
            $got = $this->client->post('link/token/get', ['link_token' => $linkToken]);
        } catch (PlaidError|PlaidRefused) {
            throw SocialOAuthRefused::exchangeFailed('Plaid');
        }

        // Newest session first: a reopened page may have run more than one.
        $sessions = array_reverse((array) ($got['link_sessions'] ?? []));
        foreach ($sessions as $session) {
            foreach ((array) ($session['results']['item_add_results'] ?? []) as $added) {
                if (is_string($added['public_token'] ?? null) && $added['public_token'] !== '') {
                    return ['public_token' => $added['public_token'], 'institution' => $added['institution']['name'] ?? null];
                }
            }
        }

        return null;
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        $linkToken = Cache::pull(self::cacheKey($code));
        if (! is_string($linkToken) || $linkToken === '') {
            throw SocialOAuthRefused::noOpenSignIn('Plaid');
        }

        $result = $this->sessionResult($linkToken);
        if ($result === null) {
            throw SocialOAuthRefused::declined('Plaid');
        }

        try {
            $exchanged = $this->client->post('item/public_token/exchange', ['public_token' => $result['public_token']]);
        } catch (PlaidError|PlaidRefused) {
            throw SocialOAuthRefused::exchangeFailed('Plaid');
        }

        $access = $exchanged['access_token'] ?? null;
        $item = $exchanged['item_id'] ?? null;
        if (! is_string($access) || $access === '' || ! is_string($item) || $item === '') {
            throw SocialOAuthRefused::exchangeFailed('Plaid');
        }

        return new SocialOAuthGrant($access, null, $this->scopes(), null, [
            'item_id' => $item,
            'institution' => $result['institution'],
        ]);
    }

    /** @return SocialAccount[] the one bank login, named as Plaid names the bank */
    public function identify(SocialOAuthGrant $grant): array
    {
        $item = $grant->extra['item_id'] ?? null;
        if (! is_string($item) || $item === '') {
            throw SocialOAuthRefused::unidentified('Plaid');
        }
        $name = trim((string) ($grant->extra['institution'] ?? '')) ?: 'Bank';

        return [new SocialAccount(remoteId: $item, displayName: $name)];
    }

    /** Best effort at disconnect: Plaid forgets the Item and stops billing it. False when it would not. */
    public function remove(string $accessToken): bool
    {
        try {
            $this->client->post('item/remove', ['access_token' => $accessToken]);

            return true;
        } catch (PlaidRefused) {
            return true;   // already gone at Plaid
        } catch (\Throwable) {
            return false;
        }
    }
}
