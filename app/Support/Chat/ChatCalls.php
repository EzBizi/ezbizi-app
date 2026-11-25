<?php

namespace App\Support\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\PhoneConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VideoGrant;

/**
 * Chat calls: voice and video, two people or a group, on Twilio Video rooms.
 *
 * One mechanism for all four, and none of them needs a phone line: a Video
 * token is the organization's own Twilio API key signing a JWT, so the
 * organization's Twilio account carries the minutes (tenant billing is the
 * tenant's). Rooms are ad hoc, created by the first person to connect and
 * closed by Twilio when the last leaves. Nothing is recorded (never store call
 * audio, Jose 2026-10-27).
 *
 * The call itself is a ChatMessage of kind `call`; its `meta` carries the
 * state: status (ringing, ongoing, answered, missed, declined), video, room,
 * started_at, answered_at, joined (user ids), duration.
 *
 * Ringing lives in the cache, per person, so GET chat/calls/ringing is one
 * cache read: the whole app polls it, not just the Chat tab.
 */
final class ChatCalls
{
    /** How long a call rings before it is missed. WhatsApp rings about 45 s. */
    public const RING_SECONDS = 45;

    /** The organization's Twilio link that can sign a token, or null. */
    public function connection(): ?PhoneConnection
    {
        return PhoneConnection::query()
            ->whereNotNull('api_key_sid')
            ->where('status', 'connected')
            ->orderByRaw("scope_type = 'organization' desc")
            ->oldest()
            ->first();
    }

    public function room(ChatMessage $call): string
    {
        return 'chat-'.$call->id;
    }

    public function token(PhoneConnection $connection, User $user, ChatMessage $call): string
    {
        $token = new AccessToken(
            (string) $connection->account_sid,
            (string) $connection->api_key_sid,
            (string) $connection->credentials,
            3600,
            $user->id,
        );
        $token->addGrant((new VideoGrant)->setRoom($this->room($call)));

        return $token->toJWT();
    }

    /** Ring everybody in the chat but the caller. */
    public function ring(Chat $chat, ChatMessage $call, User $caller, bool $video): void
    {
        $entry = [
            'call_id' => $call->id,
            'chat_id' => $chat->id,
            'chat_name' => $chat->type === 'group' ? $chat->name : null,
            'from_id' => $caller->id,
            'from_name' => $caller->name,
            'video' => $video,
            'group' => $chat->type === 'group',
            'until' => now()->addSeconds(self::RING_SECONDS)->timestamp,
        ];
        foreach ($chat->activeMembers()->where('user_id', '!=', $caller->id)->pluck('user_id') as $id) {
            $this->put($id, fn (array $rows) => [...$rows, $entry]);
        }
    }

    /** @param string[]|null $only stop it ringing for these people, or for everybody */
    public function silence(ChatMessage $call, ?array $only = null): void
    {
        $ids = $only ?? $call->chat->members()->pluck('user_id')->all();
        foreach ($ids as $id) {
            $this->put($id, fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r['call_id'] !== $call->id)));
        }
    }

    /** @return array<int, array<string, mixed>> calls ringing for this person now */
    public function ringingFor(string $userId): array
    {
        $now = now()->timestamp;

        return array_values(array_filter(
            (array) Cache::get("chat:ring:{$userId}", []),
            fn ($r) => ($r['until'] ?? 0) > $now,
        ));
    }

    private function put(string $userId, callable $change): void
    {
        $key = "chat:ring:{$userId}";
        // ponytail: read-modify-write without a lock; two calls ringing the
        // same person in the same millisecond can drop one. A Cache::lock if
        // that ever shows up.
        $rows = $change($this->ringingFor($userId));
        $rows === [] ? Cache::forget($key) : Cache::put($key, $rows, self::RING_SECONDS + 15);
    }
}
