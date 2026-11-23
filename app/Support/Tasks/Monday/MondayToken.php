<?php

namespace App\Support\Tasks\Monday;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\MondayOAuth;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A Monday connection's access token, fresh (desk, 2026-11-12: the New OAuth
 * Flow's access tokens expire; refresh tokens are single-use JWTs that rotate
 * on every refresh, and reusing one is invalid_grant and can revoke the family).
 *
 * - Refreshed before it runs out: when under 5 minutes are left.
 * - One refresher at a time per connection: a cache lock, and inside it the
 *   row is read again — the 10-minute poll, the nightly read and a webhook may
 *   all arrive with the same old pair, and only the first may spend it; the
 *   others take what it stored.
 * - The new pair is stored in one write (access, refresh, expiry together).
 * - invalid_grant marks the connection broken ("Reconnect Monday") and stops:
 *   no retry, because a retry would only spend the family faster.
 */
final class MondayToken
{
    public const MARGIN_SECONDS = 300;

    /** How long a caller waits for another to finish refreshing. */
    public int $waitSeconds = 20;

    public function __construct(
        private readonly MondayOAuth $oauth,
        private readonly TenantContext $tenant,
    ) {}

    public function for(SocialConnection $connection): string
    {
        if ($connection->status === SocialConnection::STATUS_BROKEN) {
            throw new MondayRefused((string) ($connection->status_reason ?: MondayRefused::RECONNECT));
        }
        if (! self::expiring($connection)) {
            return self::access($connection);
        }

        try {
            return Cache::lock('monday-token:'.$connection->getKey(), 60)->block($this->waitSeconds, function () use ($connection) {
                $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);

                return $this->tenant->run($org, function () use ($connection) {
                    // Another run may have refreshed while this one waited: use what it stored.
                    $connection->refresh();
                    if ($connection->status === SocialConnection::STATUS_BROKEN) {
                        throw new MondayRefused((string) ($connection->status_reason ?: MondayRefused::RECONNECT));
                    }
                    if (! self::expiring($connection)) {
                        return self::access($connection);
                    }

                    $credentials = (array) ($connection->credentials ?? []);
                    $refresh = $credentials['refresh_token'] ?? null;
                    if (! is_string($refresh) || $refresh === '') {
                        // An expiring token with nothing to renew it: only a reconnect helps.
                        $this->broken($connection);
                    }

                    try {
                        $grant = $this->oauth->refresh($refresh);
                    } catch (MondayRefused) {
                        $this->broken($connection);
                    }

                    $connection->forceFill([
                        'credentials' => array_filter([
                            'access_token' => $grant->accessToken,
                            'refresh_token' => $grant->refreshToken,
                            'scopes' => $grant->scopes ?: ($credentials['scopes'] ?? []),
                            'expires_at' => $grant->expiresIn !== null ? now()->addSeconds($grant->expiresIn)->toIso8601String() : null,
                        ], fn ($v) => $v !== null),
                        'last_reached_at' => now(),
                    ])->save();

                    return $grant->accessToken;
                });
            });
        } catch (LockTimeoutException) {
            throw new \RuntimeException('Another Monday token refresh is still running; this run tries again next time.');
        }
    }

    /** Under five minutes left (or past). No expiry recorded means a token that does not expire. */
    public static function expiring(SocialConnection $connection): bool
    {
        $at = ((array) ($connection->credentials ?? []))['expires_at'] ?? null;

        return is_string($at) && Carbon::parse($at)->lessThan(now()->addSeconds(self::MARGIN_SECONDS));
    }

    private static function access(SocialConnection $connection): string
    {
        $token = ((array) ($connection->credentials ?? []))['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new MondayRefused(MondayRefused::RECONNECT);
        }

        return $token;
    }

    private function broken(SocialConnection $connection): never
    {
        $connection->forceFill([
            'status' => SocialConnection::STATUS_BROKEN,
            'status_reason' => MondayRefused::RECONNECT,
            'last_checked_at' => now(),
        ])->save();

        throw new MondayRefused(MondayRefused::RECONNECT);
    }
}
