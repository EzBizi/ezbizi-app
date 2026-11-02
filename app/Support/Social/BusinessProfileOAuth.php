<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The tenant's own Google Business Profile — locations, reviews, replies.
 *
 * Separate from Google Ads on purpose. A chimney sweep with three Maps
 * listings and no advertising should be able to connect this and never see a
 * Google Ads consent screen; an agency running Local Services for a client who
 * manages their own listing needs the reverse. One combined connection would
 * force both on everybody and ask for a scope the customer has no use for.
 *
 * ── `business.manage` is not sensitive, and that matters ──────
 *
 * Google classifies it as an ordinary scope, so this half of EZ Leads needs no
 * verification and no demo video — unlike `adwords`. What it DOES need is
 * quota: the Business Profile APIs ship at 0 QPM and stay there until Google
 * approves an access application, which cannot even be filed until the
 * applicant has managed a verified profile for sixty days. Enabling the APIs
 * in the console does not grant quota; the two are separate gates, and a
 * project with all four APIs enabled will still fail every call.
 */
final class BusinessProfileOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/business-profile/callback';

    /** @var string[] */
    public const SCOPES = [
        'https://www.googleapis.com/auth/business.manage',
        // Which Google login this is (Jose, 2026-11-02): GoogleToken::exchange.
        'openid',
        'email',
    ];

    private const TIMEOUT = 10;

    /**
     * Account discovery is v1. Reviews are not.
     *
     * Reviews, replies, posts and media are still only served by the legacy v4
     * host, which has no v1 replacement and a long-announced deprecation. It is
     * not a separately enabled API either — access rides on `business.manage`,
     * so its absence from the console's enabled list is correct rather than a
     * missing step. Named here so the day v4 goes has one place to start.
     */
    public const ACCOUNTS_ENDPOINT = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

    public const LEGACY_V4 = 'https://mybusiness.googleapis.com/v4';

    public function provider(): SocialProvider
    {
        return SocialProvider::GoogleBusinessProfile;
    }

    public function routeKey(): string
    {
        return 'business-profile';
    }

    public function scopes(): array
    {
        return self::SCOPES;
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function requiresRefreshToken(): bool
    {
        return true;
    }

    public function notReadyBecause(): ?string
    {
        if (RedirectUri::credentialsPresent(
            config('services.business_profile.client_id'),
            config('services.business_profile.client_secret'),
        )) {
            return null;
        }

        return 'EzBizi has no Google Business Profile application set up on this installation '
            .'yet, so a Business Profile cannot be connected. Nothing is wrong with the '
            .'profile — the missing piece is at EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.business_profile.redirect'),
            self::CALLBACK_PATH,
            'Google Business Profile',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'     => (string) config('services.business_profile.client_id'),
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
        return GoogleToken::exchange('business_profile', 'Google Business Profile', $code, $this->redirectUri());
    }

    /**
     * The Business Profile ACCOUNTS this grant reaches, not the locations.
     *
     * One account can hold hundreds of locations, and asking for all of them
     * during a consent callback would make connecting slow and occasionally
     * time out. Locations are read afterwards, on the tenant's own schedule.
     *
     * @return SocialAccount[]
     *
     * @throws SocialOAuthRefused
     */
    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get(self::ACCOUNTS_ENDPOINT, ['pageSize' => 20]);
        } catch (ConnectionException) {
            throw GoogleApiRefusal::unreachable('Google Business Profile');
        }

        if ($response->failed()) {
            throw GoogleApiRefusal::from('Google Business Profile', $response);
        }

        $accounts = [];

        foreach ((array) $response->json('accounts', []) as $account) {
            $name = trim(str_replace('accounts/', '', (string) ($account['name'] ?? '')));

            if ($name === '') {
                continue;
            }

            $label = trim((string) ($account['accountName'] ?? ''));

            $accounts[] = new SocialAccount($name, $label !== '' ? $label : $name, null);
        }

        if ($accounts === []) {
            throw SocialOAuthRefused::unidentified(
                'Google Business Profile',
                'The Google account you signed in with manages no Business Profile that EzBizi can '
                .'reach, so there was nothing to connect. Sign in with the account that manages the '
                .'listing, or have it given access to that listing first.',
            );
        }

        return $accounts;
    }
}
