<?php

namespace App\Support\Web;

use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Who a website visitor is talking to (Jose, 2026-10-01: "let know the
 * customer who they are talking to", as A1 Tools' chat did).
 *
 * A visitor sees a dispatcher's FIRST name and photo, never the full name or
 * an address: the same thing A1 showed, and enough to know a person is there.
 * Photos go out as signed links that expire, because a guessable photo
 * address would list who works where (ProfileController::avatar).
 *
 * Typing is a few seconds in the cache, each way. Nothing is stored.
 */
final class ChatPeople
{
    /** How long one keystroke keeps "is typing…" up. */
    public const TYPING_SECONDS = 6;

    /** @return array{name: string, initials: string, avatar: ?string} */
    public static function person(User $user): array
    {
        $name = trim((string) $user->name);
        $first = explode(' ', $name)[0] ?? '';
        $initials = strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', $name) ?: [], 0, 2))));

        return [
            'name' => $first !== '' ? $first : 'Support',
            'initials' => $initials !== '' ? $initials : '?',
            /*
             * Signed but not expiring, and versioned by the photo itself: the
             * link changes only when the photo does. An expiring link was
             * re-signed on every plugin refresh, which the plugin read as a
             * change and purged the whole page cache every 5 minutes (Bar,
             * 2026-11-18: 6,697 purges on call.a-1chimney.com).
             */
            'avatar' => $user->avatar_path === null ? null
                : URL::signedRoute('web.chat.avatar', ['user' => $user->getKey(), 'v' => substr(sha1($user->avatar_path), 0, 10)]),
        ];
    }

    /**
     * Who sent each of our replies on this lead, by conversation id — read
     * from the audit every reply writes, as Leads itself does.
     *
     * @return array<string, User>
     */
    public static function senders(Lead $lead): array
    {
        $events = AuditEvent::query()
            ->where('action', 'lead.replied')
            ->where('target_id', $lead->getKey())
            ->where('actor_type', AuditEvent::ACTOR_USER)
            ->get(['changes', 'actor_id']);
        $users = User::query()->whereIn('id', $events->pluck('actor_id')->unique())->get()->keyBy('id');
        $out = [];

        foreach ($events as $e) {
            if (isset($users[$e->actor_id]) && ($id = (string) ($e->changes['conversation_id'] ?? '')) !== '') {
                $out[$id] = $users[$e->actor_id];
            }
        }

        return $out;
    }

    /**
     * The faces in the header before anybody answers: whoever replied to this
     * company's leads most recently, up to three.
     *
     * @return list<array{name: string, initials: string, avatar: ?string}>
     */
    public static function team(WebSite $site): array
    {
        $ids = AuditEvent::query()
            ->where('action', 'lead.replied')
            ->where('actor_type', AuditEvent::ACTOR_USER)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->whereIn('target_id', Lead::query()->where('company_id', $site->company_id)->select('id'))
            ->latest('occurred_at')
            ->limit(200)
            ->pluck('actor_id')
            ->unique()
            ->take(3);

        return User::query()->whereIn('id', $ids)->get()
            ->sortBy(fn (User $u) => $ids->search($u->getKey()))
            ->map(fn (User $u) => self::person($u))->values()->all();
    }

    public static function visitorTyping(string $leadId): void
    {
        Cache::put('chat-visitor-typing:'.$leadId, true, self::TYPING_SECONDS);
    }

    public static function isVisitorTyping(string $leadId): bool
    {
        return (bool) Cache::get('chat-visitor-typing:'.$leadId, false);
    }

    public static function agentTyping(string $leadId, User $user): void
    {
        Cache::put('chat-agent-typing:'.$leadId, (string) $user->getKey(), self::TYPING_SECONDS);
    }

    public static function typingAgent(string $leadId): ?User
    {
        $id = Cache::get('chat-agent-typing:'.$leadId);

        return is_string($id) ? User::query()->find($id) : null;
    }
}
