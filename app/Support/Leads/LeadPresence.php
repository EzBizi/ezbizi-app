<?php

namespace App\Support\Leads;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Who has a lead open right now, so two dispatchers do not answer the same
 * customer (Jose, 2026-09-29: "an active tag that shows the name of the
 * persons that currently have a specific lead open").
 *
 * Reading a lead is the check-in (the open thread re-reads it every 10
 * seconds); a check-in lasts 45, so a
 * closed thread drops off within a minute with nothing to clean up. Held in
 * the cache, not the database: it is a minute old at most and worth nothing
 * after that.
 *
 * ponytail: read-modify-write without a lock - two check-ins in the same
 * instant can drop one name for up to 10 seconds, until its next check-in.
 */
final class LeadPresence
{
    private const LASTS = 45;

    public static function checkIn(string $leadId, User $user): void
    {
        $now = now()->getTimestamp();
        $viewers = array_filter((array) Cache::get(self::key($leadId), []), fn ($v) => ($v['until'] ?? 0) > $now);
        $viewers[(string) $user->getKey()] = ['name' => (string) $user->name, 'until' => $now + self::LASTS];
        Cache::put(self::key($leadId), $viewers, self::LASTS + 15);
    }

    /**
     * Everybody else with each lead open, by lead id; leads nobody else has
     * open are left out.
     *
     * @param  list<string>  $leadIds
     * @return array<string, list<string>>
     */
    public static function others(array $leadIds, User $user): array
    {
        if ($leadIds === []) {
            return [];
        }

        $keys = array_map(self::key(...), $leadIds);
        $found = Cache::many($keys);
        $now = now()->getTimestamp();
        $out = [];

        foreach ($leadIds as $i => $id) {
            $names = [];
            foreach ((array) ($found[$keys[$i]] ?? []) as $userId => $v) {
                if ((string) $userId !== (string) $user->getKey() && ($v['until'] ?? 0) > $now) {
                    $names[] = (string) $v['name'];
                }
            }
            if ($names !== []) {
                $out[$id] = $names;
            }
        }

        return $out;
    }

    private static function key(string $leadId): string
    {
        return 'lead-viewers:'.$leadId;
    }
}
