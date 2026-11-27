<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The token-endpoint half that plain OAuth 2.0 networks share: POST a form,
 * then turn the answer into a grant or a refusal. Each network keeps its own
 * request (client credentials in a Basic header or in the body), its own form,
 * and its own reading of "this refresh token is dead".
 *
 * Pinterest, X and LinkedIn use it. The others read their own answers: TikTok
 * nests it under `data`, Thumbtack logs every refusal, Monday posts JSON, and
 * the Meta networks GET.
 */
final class OAuthTokens
{
    /**
     * @param  array<string, string>  $form  sent in this order
     *
     * @throws SocialOAuthRefused when nothing answered — the same refusal as a refused code
     */
    public static function post(PendingRequest $request, string $endpoint, array $form, string $network): Response
    {
        try {
            return $request->post($endpoint, $form);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed($network);
        }
    }

    /**
     * The grant a code exchange answered with. A refresh token is required
     * unless $lasting is false: without one the connection dies with the
     * access token.
     *
     * @throws SocialOAuthRefused
     */
    public static function exchanged(Response $response, string $network, bool $lasting = true): SocialOAuthGrant
    {
        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');

        if (! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        $refresh = is_string($refresh) && $refresh !== '' ? $refresh : null;

        if ($refresh === null && $lasting) {
            throw SocialOAuthRefused::noLastingPermission($network);
        }

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: $refresh,
            scopes: SocialOAuthGrant::parseScopes($response->json('scope')),
            expiresIn: self::expiresIn($response),
        );
    }

    /**
     * The grant a refresh answered with. $dead is the network's own reading of
     * "this refresh token will never work again", which only a new sign-in
     * mends. The refresh token sent is kept when none comes back rotated.
     *
     * @throws SocialOAuthRefused
     */
    public static function refreshed(Response $response, string $network, string $refreshToken, bool $dead): SocialOAuthGrant
    {
        if ($dead) {
            throw SocialOAuthRefused::noLongerPermitted($network.' no longer accepts this connection. It needs connecting again.');
        }

        $access = $response->json('access_token');

        if ($response->failed() || ! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        $rotated = $response->json('refresh_token');

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: is_string($rotated) && $rotated !== '' ? $rotated : $refreshToken,
            expiresIn: self::expiresIn($response),
        );
    }

    private static function expiresIn(Response $response): ?int
    {
        return is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null;
    }
}
