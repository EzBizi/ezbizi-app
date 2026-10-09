<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Bluesky has no developer console. End-user login for a product is AT
 * Protocol OAuth long-term; the thing that works today without a portal is
 * an app password against createSession. We store the refresh JWT, never
 * the app password.
 */
final class BlueskySession
{
    private const TIMEOUT = 10;

    public const PDS = 'https://bsky.social';

    /**
     * @return array{did: string, handle: string, access_jwt: string, refresh_jwt: string}
     *
     * @throws SocialOAuthRefused
     */
    public function create(string $identifier, string $appPassword): array
    {
        $identifier = trim($identifier);
        $appPassword = trim($appPassword);

        if ($identifier === '' || $appPassword === '') {
            throw SocialOAuthRefused::unidentified(
                'Bluesky',
                'Type the Bluesky handle and an app password. Use an app password from '
                .'Bluesky settings, not the account password.',
            );
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->post(self::PDS.'/xrpc/com.atproto.server.createSession', [
                    'identifier' => $identifier,
                    'password'   => $appPassword,
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('Bluesky');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified(
                'Bluesky',
                'Bluesky did not accept that handle and app password, so nothing has been '
                .'connected. Check the handle and create an app password in Bluesky settings.',
            );
        }

        $did = trim((string) $response->json('did'));
        $handle = trim((string) $response->json('handle'));
        $access = $response->json('accessJwt');
        $refresh = $response->json('refreshJwt');

        if ($did === '' || ! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '') {
            throw SocialOAuthRefused::exchangeFailed('Bluesky');
        }

        return [
            'did'         => $did,
            'handle'      => $handle !== '' ? $handle : $identifier,
            'access_jwt'  => $access,
            'refresh_jwt' => $refresh,
        ];
    }

    /**
     * A fresh pair from the stored refresh JWT.
     *
     * The refresh JWT ROTATES: the one sent here is spent, and only the one
     * handed back works next time. The caller must store it before anything
     * else can fail, and must hold a lock while it does (BlueskyPublisher).
     *
     * A 400 or 401 means the refresh JWT is expired or revoked — the app
     * password was deleted, or the account went quiet for months. That is the
     * one answer that is about the connection rather than the moment, so it is
     * the one returned as HTTP_CONFLICT for the caller to mark the row broken.
     *
     * @return array{did: string, handle: string, access_jwt: string, refresh_jwt: string}
     *
     * @throws SocialOAuthRefused
     */
    public function refresh(string $refreshJwt): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->withToken($refreshJwt)
                ->post(self::PDS.'/xrpc/com.atproto.server.refreshSession');
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('Bluesky');
        }

        if (in_array($response->status(), [400, 401], strict: true)) {
            throw SocialOAuthRefused::noLongerPermitted(
                'Bluesky no longer accepts this connection — the app password may have been '
                .'deleted. Connect the account again with a new app password.'
            );
        }

        $access = $response->json('accessJwt');
        $refresh = $response->json('refreshJwt');

        if ($response->failed() || ! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '') {
            throw SocialOAuthRefused::exchangeFailed('Bluesky');
        }

        return [
            'did'         => trim((string) $response->json('did')),
            'handle'      => trim((string) $response->json('handle')),
            'access_jwt'  => $access,
            'refresh_jwt' => $refresh,
        ];
    }
}
