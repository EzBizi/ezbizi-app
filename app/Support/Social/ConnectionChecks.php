<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use Illuminate\Support\Carbon;

/**
 * Per-check results on a connection (`social_connections.checks`), written by
 * the daily health run (sign_in), the setup read-back (messages, routing) and
 * a setup job that gave up (setup). GET integrations/overview reads them and
 * never calls a provider live.
 */
final class ConnectionChecks
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const OFF = 'off';

    public const UNKNOWN = 'unknown';

    /** When ezbizi:check-connection-health next runs (routes/console.php: dailyAt 06:40). */
    public static function nextRun(): Carbon
    {
        $next = now()->setTime(6, 40);

        return $next->isFuture() ? $next : $next->addDay();
    }

    /** Merge one check's result into the row and save it. */
    public static function record(SocialConnection $c, string $key, string $state, ?string $reason = null, ?array $fix = null, ?string $label = null): void
    {
        $checks = self::of($c);
        $checks[$key] = [
            'state' => $state,
            'reason' => $reason,
            'fix' => $fix,
            'label' => $label,
            'checked_at' => now()->toIso8601String(),
            'next_retry_at' => self::nextRun()->toIso8601String(),
        ];

        $c->forceFill(['checks' => $checks])->save();
    }

    /** @return array<string, array{state: string, reason: ?string, checked_at: ?string, next_retry_at: ?string}> */
    public static function of(SocialConnection $c): array
    {
        $raw = $c->getAttribute('checks');

        return is_array($raw) ? $raw : [];
    }
}
