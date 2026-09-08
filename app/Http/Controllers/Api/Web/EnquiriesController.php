<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\LeadConversation;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\WebsiteEnquiries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The chat widget and the contact form on a customer's website.
 *
 * ── Who is calling, and what that costs ──
 *
 * A visitor's browser, on a site we do not run, with nobody signed in. Every
 * endpoint here is therefore unauthenticated in the usual sense and identified
 * only by the site's PUBLIC key — the same `pk_` the values snippet uses, which
 * is safe in page source because it reads only what the site already publishes.
 *
 * That makes these the only routes in EzBizi where a stranger can create a row.
 * Three things hold the line, and none of them is optional:
 *
 *   1. The public key names the site, and the site names the company. A caller
 *      cannot choose where their enquiry lands.
 *   2. The rate limiter is per key AND per address, so one key cannot be used
 *      to fill a company's Leads list and one address cannot walk many keys.
 *   3. Reading a chat back needs the per-conversation token minted when it
 *      started. Knowing a site's public key does not let anybody read a
 *      stranger's conversation on that site.
 *
 * ponytail: no CAPTCHA. A determined bot with a real key can still post, and
 * the answer when that happens is a challenge on the widget rather than a
 * tighter limit — a limit low enough to stop a bot is low enough to lose a busy
 * afternoon's real enquiries.
 *
 * ── Why the visitor polls ──
 *
 * Replies reach the visitor because their widget asks again, not because we
 * push. Real-time would mean adding Reverb or Pusher — a daemon, a port and a
 * scaling story — to a feature with no users yet.
 *
 * ponytail: polling every few seconds; move to websockets when a customer has
 * enough concurrent chats for the poll to show up in the request log.
 */
class EnquiriesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly WebsiteEnquiries $enquiries,
    ) {}

    /** POST /api/v1/web/chat — a visitor opens a conversation. */
    public function startChat(Request $request): JsonResponse
    {
        $site = $this->siteForKey($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $input = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'page_url' => ['nullable', 'string', 'max:500'],
        ]);

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $input): JsonResponse {
            [$chat, $token] = $this->enquiries->startChat($site, $input['message'], [
                'name' => $input['name'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'page_url' => $input['page_url'] ?? null,
            ]);

            return response()->json([
                'chat' => $chat->getKey(),
                // Said once. It is hashed on our side and cannot be re-read.
                'token' => $token,
                'messages' => $this->messagesFor($chat),
            ], Response::HTTP_CREATED)->withHeaders($this->browserHeaders());
        });
    }

    /** POST /api/v1/web/chat/{chat}/messages — the visitor says something more. */
    public function say(Request $request, string $chat): JsonResponse
    {
        $found = $this->chatForToken($request, $chat);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        $input = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $organization = $this->tenant->runWithoutTenant(fn () => $found->organization);

        return $this->tenant->run($organization, function () use ($found, $input): JsonResponse {
            $this->enquiries->say($found, $input['message'], LeadConversation::FROM_CONSUMER);

            return response()->json([
                'messages' => $this->messagesFor($found),
            ])->withHeaders($this->browserHeaders());
        });
    }

    /** GET /api/v1/web/chat/{chat}/messages — the widget asking whether anybody answered. */
    public function messages(Request $request, string $chat): JsonResponse
    {
        $found = $this->chatForToken($request, $chat);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        $organization = $this->tenant->runWithoutTenant(fn () => $found->organization);

        return $this->tenant->run($organization, function () use ($found): JsonResponse {
            $found->forceFill(['last_seen_at' => now()])->save();

            return response()->json([
                'messages' => $this->messagesFor($found),
            ])->withHeaders($this->browserHeaders());
        });
    }

    /** POST /api/v1/web/form — one submitted contact form. */
    public function submitForm(Request $request): JsonResponse
    {
        $site = $this->siteForKey($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $input = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:40'],
            'fields.*' => ['nullable', 'string', 'max:4000'],
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'page_url' => ['nullable', 'string', 'max:500'],
        ]);

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $input): JsonResponse {
            $this->enquiries->submitForm($site, $input['fields'], [
                'name' => $input['name'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'page_url' => $input['page_url'] ?? null,
            ]);

            /*
             * No lead id in the body. The visitor has no use for it, and handing
             * a stranger the primary key of a row in somebody's CRM is a habit
             * worth not starting.
             */
            return response()->json(['received' => true], Response::HTTP_CREATED)
                ->withHeaders($this->browserHeaders());
        });
    }

    /** The site a public key names, or the refusal to send instead. */
    private function siteForKey(Request $request): WebSite|JsonResponse
    {
        $key = $request->input('key');

        if (! is_string($key) || $key === '') {
            return response()->json(
                ['message' => 'No key was given.'],
                Response::HTTP_BAD_REQUEST,
            )->withHeaders($this->browserHeaders());
        }

        $site = $this->tenant->runWithoutTenant(
            fn () => WebSite::query()->where('public_key', $key)->first(),
        );

        if ($site === null) {
            // The same answer a rotated key gets, for the reason the values
            // endpoint gives: two answers is an oracle for which keys are real.
            return response()->json(
                ['message' => 'That key is not valid.'],
                Response::HTTP_NOT_FOUND,
            )->withHeaders($this->browserHeaders());
        }

        return $site;
    }

    /**
     * The chat a visitor's token names, checked against the id in the path.
     *
     * Both must agree. Checking the token alone would let a leaked one read any
     * chat; checking the id alone would let anybody read every chat.
     */
    private function chatForToken(Request $request, string $chat): WebChat|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return response()->json(
                ['message' => 'This conversation needs its token.'],
                Response::HTTP_UNAUTHORIZED,
            )->withHeaders($this->browserHeaders());
        }

        $found = $this->tenant->runWithoutTenant(
            fn () => WebChat::query()
                ->where('id', $chat)
                ->where('token_hash', hash('sha256', $token))
                ->first(),
        );

        if ($found === null) {
            return response()->json(
                ['message' => 'That conversation is not yours.'],
                Response::HTTP_UNAUTHORIZED,
            )->withHeaders($this->browserHeaders());
        }

        return $found;
    }

    /**
     * The thread as the visitor may see it.
     *
     * Deliberately not the LeadConversation rows themselves: those carry an id,
     * a remote id and an organization id, none of which is the visitor's
     * business, and a widget that received them would put them in page source.
     *
     * @return array<int, array{from: string, text: ?string, at: ?string}>
     */
    private function messagesFor(WebChat $chat): array
    {
        return LeadConversation::query()
            ->where('lead_id', $chat->lead_id)
            ->orderBy('occurred_at')
            ->get(['participant', 'text', 'occurred_at'])
            ->map(fn (LeadConversation $row) => [
                'from' => $row->participant === LeadConversation::FROM_CONSUMER ? 'you' : 'them',
                'text' => $row->text,
                'at' => $row->occurred_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Callable from any origin, because it is called from the customer's site.
     *
     * The same argument the values endpoint makes: this is public data going
     * out and a public key coming in, on a host that is not ours by design.
     */
    private function browserHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ];
    }
}
