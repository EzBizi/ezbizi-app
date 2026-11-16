<?php

namespace App\Support\Ads;

use App\Models\SocialConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Marketing › Ads › "All accounts" (Jose, 2026-11-16: "a view that shows ALL
 * the campaigns from all the accounts in one same screen"): every account's
 * campaigns in one list, each row naming its account.
 *
 * Google and Meta report campaigns one account at a time, so this reads each
 * account once and keeps the answer for CACHE_SECONDS. Opening All again,
 * flipping back to it or re-sorting costs nothing; a change made on screen
 * forgets that account's copy (forget()), so its row is read fresh.
 * ponytail: 2 Google operations per account per cache miss (A-1: ~106 of a
 * 15,000/day ceiling); store daily campaign rows if All is opened all day.
 */
final class AllAccounts
{
    public const CACHE_SECONDS = 900;

    /**
     * @param  Collection<int, SocialConnection>  $connections
     * @param  callable(SocialConnection): list<array<string, mixed>>  $campaigns  one account's campaigns
     * @return array{campaigns: list<array<string, mixed>>, refused: list<array<string, string>>}
     */
    public static function read(Collection $connections, string $kind, Carbon $from, Carbon $to, callable $campaigns): array
    {
        $rows = [];
        $refused = [];

        foreach ($connections as $connection) {
            $key = sprintf('ads-all:%s:%s:%s:%s:%d', $kind, $connection->getKey(), $from->toDateString(), $to->toDateString(),
                (int) Cache::get(self::versionKey($connection)));
            try {
                $list = Cache::remember($key, self::CACHE_SECONDS, fn () => $campaigns($connection));
            } catch (RuntimeException $e) {
                // One refusing account must not blank the other fifty.
                $refused[] = ['connection_id' => $connection->getKey(), 'account' => (string) $connection->display_name, 'because' => $e->getMessage()];

                continue;
            }
            foreach ($list as $row) {
                $rows[] = ['connection_id' => $connection->getKey(), 'account' => (string) $connection->display_name] + $row;
            }
        }

        return ['campaigns' => $rows, 'refused' => $refused];
    }

    /** A change was made to this account: its cached campaigns are stale. */
    public static function forget(SocialConnection $connection): void
    {
        Cache::forever(self::versionKey($connection), (int) Cache::get(self::versionKey($connection)) + 1);
    }

    private static function versionKey(SocialConnection $connection): string
    {
        return 'ads-all-v:'.$connection->getKey();
    }
}
