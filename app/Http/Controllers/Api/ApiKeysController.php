<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\ApiKey;
use App\Support\Auth\ApiKeyRevert;
use App\Support\Http\InternalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person's own API keys — for an AI assistant or a script acting as them.
 *
 * Under /me on purpose: a key is the caller's identity, not an organization
 * resource. Which KIND of key a person may make is their role's `api_keys.*`
 * grant (Viewer, Editor, Full access); what the key can then DO is whatever its
 * owner can do, checked per request by the same gates a session meets.
 */
class ApiKeysController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): array
    {
        $user = $request->user();

        return [
            'api_keys' => ApiKey::only($user->tokens())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'level' => ApiKey::levelOf($token),
                    // What it can do today: the role may have been narrowed since.
                    'effective_level' => ApiKey::effectiveLevel($token, $user),
                    // Kept for installed clients that predate levels.
                    'access' => ApiKey::levelOf($token) === 'viewer' ? 'read' : 'write',
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ])->all(),
            'allowed_levels' => $this->allowedLevels($user),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'level' => ['sometimes', 'in:'.implode(',', ApiKey::LEVELS)],
            // Installed clients send read|write; write meant everything.
            'access' => ['sometimes', 'in:read,write'],
        ]);

        $level = $data['level'] ?? (($data['access'] ?? 'read') === 'write' ? 'full' : 'viewer');
        $allowed = $this->allowedLevels($request->user());

        abort_unless(in_array($level, $allowed, true), Response::HTTP_FORBIDDEN, $allowed === []
            ? 'Your role does not allow API keys. An administrator can allow them in Roles & permissions.'
            : 'Your role does not allow this kind of API key. An administrator can change that in Roles & permissions.');

        $token = $request->user()->createToken($data['name'], ApiKey::abilities($level));

        $this->audit->log('api_key.created', $token->accessToken, metadata: [
            'name' => $data['name'],
            'level' => $level,
        ]);

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $data['name'],
            'level' => $level,
            'access' => $level === 'viewer' ? 'read' : 'write',
            // Shown once. Only its hash is stored, so it cannot be shown again.
            'key' => $token->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $id): Response
    {
        $token = ApiKey::only($request->user()->tokens())->findOrFail($id);

        $token->delete();

        $this->audit->log('api_key.revoked', $token, metadata: ['name' => $token->name]);

        return response()->noContent();
    }

    /**
     * GET /api-keys/activity — what keys did, newest first.
     *
     * Your own keys; a holder of `organizations.api_activity.view` (the Settings ›
     * API activity tab; every Administrator) sees the organization's and may
     * narrow it with `user_id`. Read from the audit trail that
     * EnforceApiKeyAccess writes, so it cannot disagree with it.
     */
    public function activity(Request $request): array
    {
        $user = $request->user();
        $admin = $this->seesEveryone($user);

        $events = $this->visible($user)
            ->when($admin && $request->filled('user_id'), fn ($q) => $q->where('actor_id', $request->string('user_id')))
            ->when($request->filled('key_id'), fn ($q) => $q->where('target_id', (string) $request->integer('key_id')))
            ->when($request->filled('before'), fn ($q) => $q->where('occurred_at', '<', $request->date('before')))
            ->orderByDesc('occurred_at')
            // occurred_at is whole seconds; the UUIDv7 id breaks ties in call order.
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $reverted = $this->revertedAt($events->pluck('id')->all());

        return [
            'activity' => $events->map(fn (AuditEvent $e) => [
                'at' => $e->occurred_at?->toIso8601String(),
                'action' => $e->action,
                'who' => $e->actor_label,
                'user_id' => $e->actor_id,
                'key_id' => $e->target_id,
                'key_name' => $e->target_label,
                'method' => $e->metadata['method'] ?? null,
                'path' => $e->metadata['path'] ?? null,
                'status' => $e->metadata['status'] ?? null,
                'via' => $e->metadata['via'] ?? null,
                'sent' => $this->decoded($e->changes['body'] ?? null),
                // The record as it was just before the change, to put it back.
                'before' => $this->decoded($e->changes['before'] ?? null),
                'id' => $e->getKey(),
                'revertable' => ! isset($reverted[$e->getKey()]) && $this->revertible($e) !== null,
                'reverted_at' => $reverted[$e->getKey()] ?? null,
            ])->all(),
            'summary' => $this->summary($user),
        ];
    }

    /**
     * The last day of keyed calls in four numbers, for the tiles above the
     * redesigned API activity list (UI queue 111).
     *
     * Through `visible()`, so a tile can never count a call its owner could
     * not open in the list beneath it; but over the whole day rather than the
     * page, and without the list's narrowing, because a tile that changed as
     * you scrolled would be describing the scroll. Calls only: creating or
     * revoking a key is not a call made with one. A change is anything but a
     * GET; refused is what answered 400 or worse. One query.
     *
     * @return array{keys: int, calls: int, changes: int, refused: int}
     */
    private function summary(User $user): array
    {
        $row = $this->visible($user)
            ->where('action', 'api_key.request')
            ->where('occurred_at', '>=', now()->subDay())
            ->toBase()
            ->selectRaw('COUNT(DISTINCT target_id) AS key_count')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw("SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.method')) <> 'GET' THEN 1 ELSE 0 END) AS changes")
            ->selectRaw("SUM(CASE WHEN CAST(JSON_EXTRACT(metadata, '$.status') AS UNSIGNED) >= 400 THEN 1 ELSE 0 END) AS refused")
            ->first();

        return [
            'keys' => (int) $row->key_count,
            'calls' => (int) $row->calls,
            'changes' => (int) $row->changes,
            'refused' => (int) $row->refused,
        ];
    }

    /**
     * POST /api-keys/activity/{event}/revert — put a keyed change back.
     *
     * Only an allowlisted endpoint (ApiKeyRevert), only the fields the change
     * sent, and only while the record still reads as the change left it: if
     * somebody changed it since, reverting would undo their work too, so it
     * refuses and says so. The write goes out as the person pressing the
     * button, through every gate they meet — this grants nothing.
     */
    public function revert(Request $request, string $event): JsonResponse
    {
        $user = $request->user();
        $e = $this->visible($user)->where('action', 'api_key.request')->findOrFail($event);

        abort_if(
            AuditEvent::query()->where('organization_id', $user->organization_id)
                ->where('action', 'api_key.reverted')->where('metadata->event_id', $e->getKey())->exists(),
            Response::HTTP_CONFLICT,
            'This change has already been put back.',
        );

        $plan = $this->revertible($e) ?? abort(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'This change cannot be put back automatically. What it replaced is shown above, so it can be restored by hand.',
        );
        [$strategy, $uri, $sent, $before, $after] = $plan;

        $current = InternalRequest::send($request, 'GET', $uri);
        abort_unless($current->isSuccessful(), $current->getStatusCode(), 'The record could not be read, so it was left as it is.');
        abort_if(
            ApiKeyRevert::extract($strategy, (array) json_decode((string) $current->getContent(), true), $sent)
                != ApiKeyRevert::extract($strategy, $after, $sent),
            Response::HTTP_CONFLICT,
            'This has been changed again since, so putting it back would undo those later changes too. Nothing was changed.',
        );

        $write = InternalRequest::send($request, $e->metadata['method'], $uri, ApiKeyRevert::body($strategy, $before, $sent));
        if (! $write->isSuccessful()) {
            return response()->json(
                ['message' => 'It could not be put back: '.(json_decode((string) $write->getContent(), true)['message'] ?? 'the save was refused.')],
                $write->getStatusCode(),
            );
        }

        $now = (array) json_decode((string) InternalRequest::send($request, 'GET', $uri)->getContent(), true);
        $restored = ApiKeyRevert::extract($strategy, $now, $sent) == ApiKeyRevert::extract($strategy, $before, $sent);

        $this->audit->log('api_key.reverted', metadata: [
            'event_id' => $e->getKey(),
            'path' => $e->metadata['path'] ?? null,
            'restored' => $restored,
        ]);

        return response()->json($restored
            ? ['message' => 'Put back.', 'reverted' => true]
            : ['message' => 'Saved, but the record does not read exactly as it did before. Check it.', 'reverted' => false]);
    }

    /** [strategy, uri, sent, before, after] when this change can be put back, else null. */
    private function revertible(AuditEvent $e): ?array
    {
        $method = $e->metadata['method'] ?? '';
        $path = (string) ($e->metadata['path'] ?? '');
        $strategy = ApiKeyRevert::strategyFor($method, $path);
        $sent = json_decode($e->changes['body'] ?? '', true);
        $before = json_decode($e->changes['before'] ?? '', true);
        $after = json_decode($e->changes['after'] ?? '', true);

        if ($strategy === null || ! is_array($sent) || ! is_array($before) || ! is_array($after)
            || ($e->metadata['status'] ?? 500) >= 300
            // A secret was blanked in the log; writing "[redacted]" back would destroy it.
            || in_array('[redacted]', ApiKeyRevert::extract($strategy, $before, $sent), true)) {
            return null;
        }

        $query = $e->metadata['query'] ?? null;

        return [$strategy, '/'.$path.($query ? '?'.$query : ''), $sent, $before, $after];
    }

    /** The Settings › API activity tab's grant; Administrators hold it. */
    private function seesEveryone(User $user): bool
    {
        return $user->hasPermission('organizations.api_activity.view');
    }

    private function visible(User $user)
    {
        return AuditEvent::query()
            ->where('organization_id', $user->organization_id)
            ->where('action', 'like', 'api_key.%')
            ->when(! $this->seesEveryone($user), fn ($q) => $q->where('actor_id', $user->getKey()));
    }

    /** @return array<string, string> event id => when it was put back */
    private function revertedAt(array $ids): array
    {
        return AuditEvent::query()->where('action', 'api_key.reverted')
            ->whereIn('metadata->event_id', $ids)
            ->get()
            ->mapWithKeys(fn (AuditEvent $r) => [$r->metadata['event_id'] => $r->occurred_at?->toIso8601String()])
            ->all();
    }

    /** Stored as text so a capped body still fits; served as JSON when it parses. */
    private function decoded(?string $text): mixed
    {
        return $text === null ? null : (json_decode($text, true) ?? $text);
    }

    /** Every level up to the strongest this person's role allows. */
    private function allowedLevels(User $user): array
    {
        $granted = ApiKey::grantedTo($user);

        return $granted === null
            ? []
            : array_slice(ApiKey::LEVELS, 0, array_search($granted, ApiKey::LEVELS, true) + 1);
    }
}
