<?php

namespace App\Support\Chat;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Chat's stand-in for a websocket: a per-person revision, typing flags and
 * presence, all in the cache.
 *
 * The client polls GET chat/pulse every couple of seconds. That costs two
 * cache reads; only when `rev` moves does it fetch the chat list or thread.
 * ponytail: polling, because the app has no socket transport. A broadcaster
 * (Reverb) can replace bump()/rev() without the client's screens changing.
 */
final class ChatPulse
{
    /** Seen within this many seconds = online. The client pulses every 3s in front, 20s behind. */
    private const ONLINE_SECONDS = 25;

    private const TYPING_SECONDS = 6;

    /**
     * Move the revision for every active member, plus `also` (someone just
     * removed), or for `only` those people (a change to their own settings).
     *
     * @param  string[]  $also
     * @param  string[]|null  $only
     */
    public function bump(Chat $chat, array $also = [], ?array $only = null): void
    {
        $ids = $only ?? [...$chat->activeMembers()->pluck('user_id')->all(), ...$also];
        $rev = (string) Str::ulid();
        foreach (array_unique($ids) as $id) {
            Cache::put("chat:rev:{$id}", $rev, now()->addDays(7));
        }
    }

    public function rev(string $userId): string
    {
        return (string) Cache::get("chat:rev:{$userId}", '0');
    }

    public function seen(string $userId): void
    {
        Cache::put("chat:seen:{$userId}", now()->timestamp, now()->addDays(30));
    }

    public function lastSeen(string $userId): ?string
    {
        $at = Cache::get("chat:seen:{$userId}");

        return $at ? now()->setTimestamp((int) $at)->toIso8601String() : null;
    }

    /**
     * @param  string[]  $userIds
     * @return string[] the ones online now
     */
    public function online(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $seen = Cache::many(array_map(fn ($id) => "chat:seen:{$id}", $userIds));
        $cutoff = now()->timestamp - self::ONLINE_SECONDS;

        return array_values(array_filter($userIds, fn ($id) => (int) ($seen["chat:seen:{$id}"] ?? 0) >= $cutoff));
    }

    public function typing(Chat $chat, User $user, string $state): void
    {
        $key = "chat:typing:{$chat->id}";
        $now = now()->timestamp;
        $map = array_filter((array) Cache::get($key, []), fn ($v) => ($v['until'] ?? 0) > $now);
        if ($state === 'stop') {
            unset($map[$user->id]);
        } else {
            $map[$user->id] = ['until' => $now + self::TYPING_SECONDS, 'state' => $state, 'name' => $user->name];
        }
        Cache::put($key, $map, self::TYPING_SECONDS * 2);
        // Typing is shown from the pulse itself, so it needs no rev bump.
    }

    /**
     * @param  string[]  $chatIds
     * @return array<string, array<int, array{user_id: string, name: string, state: string}>>
     */
    public function typingIn(array $chatIds, string $except): array
    {
        if ($chatIds === []) {
            return [];
        }
        $now = now()->timestamp;
        $out = [];
        foreach (Cache::many(array_map(fn ($id) => "chat:typing:{$id}", $chatIds)) as $key => $map) {
            foreach ((array) $map as $userId => $row) {
                if ($userId !== $except && ($row['until'] ?? 0) > $now) {
                    $out[substr($key, strlen('chat:typing:'))][] = ['user_id' => $userId, 'name' => $row['name'], 'state' => $row['state']];
                }
            }
        }

        return $out;
    }
}
