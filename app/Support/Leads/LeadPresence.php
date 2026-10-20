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
 * seconds); a check-in lasts 25. Closing the thread checks out at once
 * (checkOut, POST /leads/{id}/leave) - Jose, 2026-10-01: a name lingering on
 * leads somebody had already left "can be a bit confusing". The 25 seconds
 * are only for a thread that never said goodbye: a closed laptop, a crash. Held in
 * the cache, not the database: it is a minute old at most and worth nothing
 * after that.
 *
 * ponytail: read-modify-write without a lock - two check-ins in the same
 * instant can drop one name for up to 10 seconds, until its next check-in.
 */
final class LeadPresence
{
    private const LASTS = 25;

    /** The reader closed the thread: off the lead now, not in 25 seconds. */
    /** How long a typist holds the lead after their last keystroke. */
    private const REPLYING_LASTS = 60;

    /**
     * Somebody is writing to the customer (Jose, 2026-10-20: two people
     * answered the same lead within a minute). Everyone else viewing it sees
     * "<name> is replying" and their composer waits, until this person sends,
     * stops typing for a minute, or leaves.
     */
    public static function replying(string $leadId, User $user): void
    {
        Cache::put(self::replyingKey($leadId), ['id' => (string) $user->getKey(), 'name' => (string) $user->name], self::REPLYING_LASTS);
    }

    /** Who else is replying to this lead right now, or null. */
    public static function replyingOther(string $leadId, User $user): ?string
    {
        $holder = Cache::get(self::replyingKey($leadId));

        return is_array($holder) && ($holder['id'] ?? null) !== (string) $user->getKey() ? (string) ($holder['name'] ?? '') : null;
    }

    /** The hold ends when its holder sends or leaves; nobody else can end it. */
    public static function stopReplying(string $leadId, User $user): void
    {
        $holder = Cache::get(self::replyingKey($leadId));
        if (is_array($holder) && ($holder['id'] ?? null) === (string) $user->getKey()) {
            Cache::forget(self::replyingKey($leadId));
        }
    }

    private static function replyingKey(string $leadId): string
    {
        return 'lead-replying:'.$leadId;
    }

    public static function checkOut(string $leadId, User $user): void
    {
        self::stopReplying($leadId, $user);
        $viewers = (array) Cache::get(self::key($leadId), []);
        unset($viewers[(string) $user->getKey()]);
        Cache::put(self::key($leadId), $viewers, self::LASTS + 15);
    }

    /**
     * A role marked hidden on leads (marketing, somebody inspecting) never
     * checks in, so dispatchers are not told a lead is being handled by
     * somebody who will not answer it (Mia, 2026-10-02).
     */
    public static function checkIn(string $leadId, User $user): void
    {
        if ($user->role?->hidden_on_leads) {
            return;
        }

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
