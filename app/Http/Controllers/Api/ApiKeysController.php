<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\ApiKey;
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
        // The Settings › API activity tab's grant; Administrators hold it.
        $admin = $user->hasPermission('organizations.api_activity.view');

        $events = AuditEvent::query()
            ->where('organization_id', $user->organization_id)
            ->where('action', 'like', 'api_key.%')
            ->when(! $admin, fn ($q) => $q->where('actor_id', $user->getKey()))
            ->when($admin && $request->filled('user_id'), fn ($q) => $q->where('actor_id', $request->string('user_id')))
            ->when($request->filled('key_id'), fn ($q) => $q->where('target_id', (string) $request->integer('key_id')))
            ->when($request->filled('before'), fn ($q) => $q->where('occurred_at', '<', $request->date('before')))
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

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
            ])->all(),
        ];
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
