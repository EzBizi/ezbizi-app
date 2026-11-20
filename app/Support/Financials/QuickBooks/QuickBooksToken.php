<?php

namespace App\Support\Financials\QuickBooks;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\QuickBooksOAuth;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A QuickBooks connection's access token, fresh. Intuit's access tokens last
 * an hour and its refresh tokens rotate: once a refresh returns a new one the
 * old one is spent. So, as MondayToken:
 *
 * - renewed when under 5 minutes are left;
 * - one renewer per connection (a cache lock), the row re-read inside it so a
 *   caller that waited takes what the first one stored;
 * - access, refresh and expiry stored in one write;
 * - invalid_grant marks the card broken ("Reconnect QuickBooks"), no retry.
 */
final class QuickBooksToken
{
    public const MARGIN_SECONDS = 300;

    public int $waitSeconds = 20;

    public function __construct(
        private readonly QuickBooksOAuth $oauth,
        private readonly TenantContext $tenant,
    ) {}

    public function for(SocialConnection $connection): string
    {
        if ($connection->status === SocialConnection::STATUS_BROKEN) {
            throw new QuickBooksRefused((string) ($connection->status_reason ?: QuickBooksRefused::RECONNECT));
        }
        if (! self::expiring($connection)) {
            return self::access($connection);
        }

        try {
            return Cache::lock('quickbooks-token:'.$connection->getKey(), 60)->block($this->waitSeconds, function () use ($connection) {
                $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);

                return $this->tenant->run($org, function () use ($connection) {
                    // Another run may have renewed while this one waited: use what it stored.
                    $connection->refresh();
                    if ($connection->status === SocialConnection::STATUS_BROKEN) {
                        throw new QuickBooksRefused((string) ($connection->status_reason ?: QuickBooksRefused::RECONNECT));
                    }
                    if (! self::expiring($connection)) {
                        return self::access($connection);
                    }

                    $credentials = (array) ($connection->credentials ?? []);
                    $refresh = $credentials['refresh_token'] ?? null;
                    if (! is_string($refresh) || $refresh === '') {
                        $this->broken($connection);
                    }

                    try {
                        $grant = $this->oauth->refresh($refresh);
                    } catch (QuickBooksRefused) {
                        $this->broken($connection);
                    }

                    $connection->forceFill([
                        'credentials' => array_filter([
                            'access_token' => $grant->accessToken,
                            // Intuit may hand back the same one; either way it is the live one now.
                            'refresh_token' => $grant->refreshToken ?? $refresh,
                            'scopes' => $grant->scopes ?: ($credentials['scopes'] ?? []),
                            'expires_at' => now()->addSeconds($grant->expiresIn ?? 3600)->toIso8601String(),
                        ], fn ($v) => $v !== null),
                        'last_reached_at' => now(),
                    ])->save();

                    return $grant->accessToken;
                });
            });
        } catch (LockTimeoutException) {
            throw new \RuntimeException('Another QuickBooks token refresh is still running; this run tries again next time.');
        }
    }

    /** Under five minutes left, past, or no expiry recorded (an Intuit token always expires). */
    public static function expiring(SocialConnection $connection): bool
    {
        $at = ((array) ($connection->credentials ?? []))['expires_at'] ?? null;

        return ! is_string($at) || Carbon::parse($at)->lessThan(now()->addSeconds(self::MARGIN_SECONDS));
    }

    private static function access(SocialConnection $connection): string
    {
        $token = ((array) ($connection->credentials ?? []))['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new QuickBooksRefused(QuickBooksRefused::RECONNECT);
        }

        return $token;
    }

    private function broken(SocialConnection $connection): never
    {
        $connection->forceFill([
            'status' => SocialConnection::STATUS_BROKEN,
            'status_reason' => QuickBooksRefused::RECONNECT,
            'last_checked_at' => now(),
        ])->save();

        throw new QuickBooksRefused(QuickBooksRefused::RECONNECT);
    }
}
