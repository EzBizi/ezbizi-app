<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The tenant's own Google Ads account, for Local Services enquiries (EZ Leads).
 *
 * Read, not managed. EzBizi lists the account's Local Services leads and its
 * reporting; it does not touch bids, budgets, keywords or campaign structure.
 * That restraint is not politeness — it is what the Basic Access application
 * says, and what the scope justification Google approved says.
 *
 * ── One scope, and no narrower one exists ─────────────────────
 *
 * The Google Ads API publishes exactly one OAuth scope. There is no read-only
 * variant, so "why can you not ask for less" has a short answer: there is no
 * less. Google marks it sensitive, which means a consent screen carrying it
 * needs verification and a demo video before it can serve real customers.
 * Until that clears the app still works — it shows the unverified-app screen
 * and draws on a 100-user lifetime cap that cannot be reset, which is why
 * demos are recorded against test accounts and not production traffic.
 */
final class GoogleAdsOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/google-ads/callback';

    /** @var string[] */
    public const SCOPES = [
        'https://www.googleapis.com/auth/adwords',
    ];

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::GoogleAds;
    }

    public function routeKey(): string
    {
        return 'google-ads';
    }

    public function scopes(): array
    {
        return self::SCOPES;
    }

    public function usesPkce(): bool
    {
        return false;
    }

    /**
     * Yes, and Google only hands one over when asked in a particular way.
     *
     * `access_type=offline` plus `prompt=consent` below. Without the second,
     * a customer who has connected once before is sent back with an access
     * token and no refresh token, and the nightly sync stops working the
     * following hour for a reason nothing on screen would explain.
     */
    public function requiresRefreshToken(): bool
    {
        return true;
    }

    public function notReadyBecause(): ?string
    {
        if (! RedirectUri::credentialsPresent(
            config('services.google_ads.client_id'),
            config('services.google_ads.client_secret'),
        )) {
            return 'EzBizi has no Google Ads application set up on this installation yet, so a '
                .'Google Ads account cannot be connected. Nothing is wrong with the account — '
                .'the missing piece is at EzBizi\'s end.';
        }

        // Separate sentence from the one above because it is a different
        // problem with a different fix. The OAuth app can be perfectly set up
        // while the developer token is still the one Google issues at Test
        // level, which reaches test accounts only — connecting would succeed
        // and then every read would fail, which is the worst of both.
        if (! is_string(config('services.google_ads.developer_token'))
            || config('services.google_ads.developer_token') === '') {
            return 'EzBizi has a Google Ads application but no developer token, and Google will '
                .'not return advertising data without one. Connecting now would appear to work '
                .'and then show nothing.';
        }

        return null;
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.google_ads.redirect'),
            self::CALLBACK_PATH,
            'Google Ads',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'     => (string) config('services.google_ads.client_id'),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => implode(' ', self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
            'state'         => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        return GoogleToken::exchange('google_ads', 'Google Ads', $code, $this->redirectUri());
    }

    /**
     * Every Ads account this grant can reach.
     *
     * `listAccessibleCustomers` returns resource names — `customers/1234567890`
     * — and nothing else, no descriptive name. A picker showing ten rows of
     * digits is a picker nobody can use, so each id is formatted the way Google
     * writes it everywhere else (123-456-7890) and that is what a person sees
     * until they can be named from a later call.
     *
     * @return SocialAccount[]
     *
     * @throws SocialOAuthRefused
     */
    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),
                ])
                ->timeout(self::TIMEOUT)
                ->get(self::endpoint().'/customers:listAccessibleCustomers');
        } catch (ConnectionException) {
            throw GoogleApiRefusal::unreachable('Google Ads');
        }

        if ($response->failed()) {
            throw GoogleApiRefusal::from('Google Ads', $response);
        }

        $names = $response->json('resourceNames');

        if (! is_array($names) || $names === []) {
            throw SocialOAuthRefused::unidentified('Google Ads', self::ownsNothing());
        }

        $accounts = [];
        $seen     = [];
        $managers = [];

        foreach ($names as $name) {
            $id = trim(str_replace('customers/', '', (string) $name));

            if ($id === '') {
                continue;
            }

            $about      = self::describe($grant->accessToken, $id);
            $accounts[] = new SocialAccount($id, $about['name'], null, isManager: $about['manager']);
            $seen[]     = $id;

            if ($about['manager']) {
                $managers[] = $id;
            }
        }

        /*
         * And every enabled client of every manager, read THROUGH it.
         *
         * listAccessibleCustomers names the accounts the signed-in user
         * reaches directly. A-1 Chimney's user reaches twenty-two; the Ads
         * Manager among them has thirty-six enabled clients. The other
         * twenty-three - Stamford, Hamden, Providence, Seattle, St Louis -
         * answer PERMISSION_DENIED to any read that does not carry the
         * manager as login-customer-id, and were never connected, so their
         * leads never arrived (measured 2026-09-09). A client the user also
         * reaches directly keeps its direct row; the manager route is for the
         * ones that have no other.
         */
        foreach ($managers as $managerId) {
            foreach (self::clientsOf($grant->accessToken, $managerId) as $client) {
                if (in_array($client->remoteId, $seen, true)) {
                    continue;
                }

                $accounts[] = $client;
                $seen[]     = $client->remoteId;
            }
        }

        if ($accounts === []) {
            throw SocialOAuthRefused::unidentified('Google Ads', self::ownsNothing());
        }

        return $accounts;
    }

    /**
     * The account's name and whether it is a manager, in one read.
     *
     * A failed read is not a failed connect: the account still exists and
     * its leads still arrive, it just wears its digits until
     * `leads:name-accounts` names it, and is treated as not a manager.
     *
     * @return array{name: string, manager: bool}
     */
    public static function describe(string $accessToken, string $customerId, ?string $loginCustomerId = null): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'developer-token'   => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $loginCustomerId ?: $customerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(
                    self::endpoint()."/customers/{$customerId}/googleAds:search",
                    ['query' => 'SELECT customer.id, customer.descriptive_name, customer.manager FROM customer'],
                );
        } catch (ConnectionException) {
            return ['name' => self::readableId($customerId), 'manager' => false];
        }

        $customer = $response->ok() ? (array) ($response->json('results.0.customer') ?? []) : [];
        $name     = trim((string) ($customer['descriptiveName'] ?? ''));

        return [
            'name'    => $name !== '' ? $name : self::readableId($customerId),
            'manager' => (bool) ($customer['manager'] ?? false),
        ];
    }

    /**
     * Every ENABLED, non-manager client under a manager, at any depth, each
     * marked to be read through that manager. A refused or unreachable
     * listing is an empty list: the manager's own row still connects.
     *
     * @return SocialAccount[]
     */
    public static function clientsOf(string $accessToken, string $managerId): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'developer-token'   => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $managerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(
                    self::endpoint()."/customers/{$managerId}/googleAds:search",
                    ['query' => 'SELECT customer_client.id, customer_client.descriptive_name, '
                        .'customer_client.level, customer_client.manager, customer_client.status '
                        .'FROM customer_client'],
                );
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->ok()) {
            return [];
        }

        $clients = [];

        foreach ((array) $response->json('results', []) as $row) {
            $c  = (array) (($row['customerClient'] ?? []));
            $id = trim((string) ($c['id'] ?? ''));

            if ($id === '' || $id === $managerId || ($c['manager'] ?? false) || ($c['status'] ?? '') !== 'ENABLED') {
                continue;
            }

            $name      = trim((string) ($c['descriptiveName'] ?? ''));
            $clients[] = new SocialAccount($id, $name !== '' ? $name : self::readableId($id), null, loginCustomerId: $managerId);
        }

        return $clients;
    }

    /**
     * The account's name as Google shows it, or its digits when Google will not say.
     *
     * `listAccessibleCustomers` returns resource names and nothing else, so for
     * a week every connection was stored as "405-172-9356" — and A-1 Chimney
     * has twenty-two of those, indistinguishable on a screen. Google keeps a
     * descriptive name per customer: measured live on 2026-09-08, 4051729356 is
     * "A1 Chimney - Ads Manager" and 4941850746 is "A1 Chimney - Cleveland",
     * which is also how it became clear that the first produces no leads and
     * the second produces most of them. One GAQL read per account, at connect
     * time only.
     *
     * A failed read is not a failed connect. The account still exists and its
     * leads still arrive; it just wears its digits until the next reconnect or
     * `leads:name-accounts` names it. Throwing here would refuse a whole
     * connection over a label.
     */
    public static function nameOf(string $accessToken, string $customerId, ?string $loginCustomerId = null): string
    {
        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'developer-token'   => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $loginCustomerId ?: $customerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(
                    self::endpoint()."/customers/{$customerId}/googleAds:search",
                    ['query' => 'SELECT customer.id, customer.descriptive_name FROM customer'],
                );
        } catch (ConnectionException) {
            return self::readableId($customerId);
        }

        $name = $response->ok()
            ? trim((string) ($response->json('results.0.customer.descriptiveName') ?? ''))
            : '';

        return $name !== '' ? $name : self::readableId($customerId);
    }

    /**
     * The one refusal on this path that is not EzBizi's fault and not Google's.
     *
     * Consent succeeded, the token is good, and the answer is an empty list:
     * this Google account simply does not own or manage an Ads account. It is
     * the common way to get here — signing in with the address you read email
     * on rather than the one the advertising sits under — and it is the only
     * cause a customer can fix themselves, so the sentence says how.
     */
    private static function ownsNothing(): string
    {
        return 'The Google account you signed in with has no Google Ads account that EzBizi can '
            .'reach, so there was nothing to connect. Sign in with the account that owns the '
            .'advertising, or have it added to that manager account first.';
    }

    /** `1234567890` → `123-456-7890`, the form Google shows everywhere else. */
    public static function readableId(string $customerId): string
    {
        $digits = preg_replace('/\D/', '', $customerId) ?? '';

        return strlen($digits) === 10
            ? substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6)
            : $customerId;
    }

    /**
     * The API version is configuration, not a constant.
     *
     * Google retires Ads API versions on a published schedule and a retired one
     * answers 404 to everything. Pinning it in `services.php` means the day it
     * has to move is an env change on the box rather than a release.
     */
    public static function endpoint(): string
    {
        return 'https://googleads.googleapis.com/'
            .trim((string) config('services.google_ads.version', 'v24'), '/');
    }
}
