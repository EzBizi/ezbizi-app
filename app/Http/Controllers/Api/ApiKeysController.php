<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit\AuditLogger;
use App\Support\Auth\ApiKey;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person's own API keys — for an AI assistant or a script acting as them.
 *
 * Under /me on purpose: a key is the caller's identity, not an organization
 * resource, so it needs no module permission. What it can DO is whatever its
 * owner can do, checked per request by the same gates a session meets.
 */
class ApiKeysController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): array
    {
        return [
            'api_keys' => $request->user()->tokens()
                ->where('name', 'like', ApiKey::PREFIX.'%')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => ApiKey::label($token),
                    'access' => $token->can('*') ? 'write' : 'read',
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'access' => ['sometimes', 'in:read,write'],
        ]);

        $write = ($data['access'] ?? 'read') === 'write';
        $token = $request->user()->createToken(
            ApiKey::PREFIX.$data['name'],
            $write ? ['*'] : [ApiKey::READ],
        );

        $this->audit->log('user.api_key_created', $token->accessToken, metadata: [
            'name' => $data['name'],
            'access' => $write ? 'write' : 'read',
        ]);

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $data['name'],
            'access' => $write ? 'write' : 'read',
            // Shown once. Only its hash is stored, so it cannot be shown again.
            'key' => $token->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $id): Response
    {
        $token = $request->user()->tokens()
            ->where('name', 'like', ApiKey::PREFIX.'%')
            ->findOrFail($id);

        $token->delete();

        $this->audit->log('user.api_key_revoked', $token, metadata: ['name' => ApiKey::label($token)]);

        return response()->noContent();
    }
}
