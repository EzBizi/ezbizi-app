<?php

namespace App\Support\Financials\Plaid;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Plaid's API: every call is a JSON POST carrying EzBizi's client_id and
 * secret (services.plaid). A connection's access_token lives only in its
 * encrypted credentials and is read here, never logged, never returned.
 *
 * An error that only the bank login can fix (ITEM_LOGIN_REQUIRED and the
 * like) is PlaidRefused: the card turns to "Reconnect". Anything else is a
 * RuntimeException: nothing was spent, the next run tries again.
 */
final class PlaidClient
{
    /** Errors that mean the person has to sign in to their bank again (update mode). */
    public const RELINK = ['ITEM_LOGIN_REQUIRED', 'PENDING_EXPIRATION', 'ACCESS_NOT_GRANTED', 'NO_ACCOUNTS', 'INVALID_CREDENTIALS', 'INSUFFICIENT_CREDENTIALS', 'USER_SETUP_REQUIRED', 'MFA_NOT_SUPPORTED'];

    /** Errors that mean the bank login is gone for good: connect it again from scratch. */
    public const GONE = ['ITEM_NOT_FOUND', 'INVALID_ACCESS_TOKEN', 'USER_PERMISSION_REVOKED'];

    private const TIMEOUT = 30;

    public static function ready(): bool
    {
        return filled(config('services.plaid.client_id')) && filled(config('services.plaid.secret'));
    }

    public static function host(): string
    {
        return config('services.plaid.environment') === 'sandbox' ? 'https://sandbox.plaid.com' : 'https://production.plaid.com';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PlaidRefused when the bank login must be redone
     * @throws PlaidError on any other Plaid error (its code is on the exception)
     */
    public function post(string $path, array $body = []): array
    {
        try {
            $response = Http::acceptJson()->asJson()->timeout(self::TIMEOUT)
                ->post(self::host().'/'.ltrim($path, '/'), [
                    'client_id' => (string) config('services.plaid.client_id'),
                    'secret' => (string) config('services.plaid.secret'),
                ] + $body);
        } catch (ConnectionException) {
            throw new PlaidError('Plaid did not answer.', 'CONNECTION');
        }

        $json = (array) $response->json();
        if ($response->successful()) {
            return $json;
        }

        $code = (string) ($json['error_code'] ?? 'HTTP_'.$response->status());
        // Plaid's display_message is written for end users; error_message is for developers. Neither carries a token.
        $said = (string) ($json['display_message'] ?? $json['error_message'] ?? 'Plaid refused the request.');
        if (in_array($code, self::RELINK, true)) {
            throw new PlaidRefused(PlaidRefused::RELINK, $code);
        }
        if (in_array($code, self::GONE, true)) {
            throw new PlaidRefused(PlaidRefused::GONE, $code);
        }

        throw new PlaidError(mb_substr($said, 0, 300), $code);
    }

    /** @return array<string, mixed> */
    public function forItem(SocialConnection $c, string $path, array $body = []): array
    {
        $token = ((array) ($c->credentials ?? []))['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new PlaidRefused(PlaidRefused::GONE, 'NO_TOKEN');
        }

        return $this->post($path, ['access_token' => $token] + $body);
    }
}
