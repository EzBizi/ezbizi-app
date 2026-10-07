<?php

namespace App\Http\Middleware;

use App\Support\Auth\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an API key may not do, whatever its owner may.
 *
 * Viewer keys refuse every write and Editor keys refuse DELETE — keyed off the
 * verb, like `subscription` and `verified`, so a new endpoint is covered without
 * anybody listing it.
 * EVERY key refuses the account's own security: a leaked key must not be able
 * to change the password, switch off a second factor, mint more keys or delete
 * the person. Sessions carry no key prefix and pass untouched.
 */
class EnforceApiKeyAccess
{
    private const ACCOUNT_SECURITY = [
        'api/*/me/password',
        'api/*/me/totp', 'api/*/me/totp/*',
        'api/*/me/sms-codes', 'api/*/me/sms-codes/*',
        'api/*/me/development/*',
        'api/*/me/sessions',
        'api/*/me/api-keys', 'api/*/me/api-keys/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! ApiKey::is($token)) {
            return $next($request);
        }

        if (! $request->isMethodSafe()
            && ($request->is(...self::ACCOUNT_SECURITY) || $request->is('api/*/me') && $request->isMethod('DELETE'))) {
            return $this->refuse('An API key cannot change sign-in security or delete the account. Sign in to EzBizi to do that.', 'api_key_account_security');
        }

        // Its own level, capped by what the owner's role allows TODAY: taking the
        // api_keys tick away downgrades or stops keys already made.
        $level = ApiKey::effectiveLevel($token, $request->user());

        if ($level === null) {
            return $this->refuse('Your role no longer allows API keys. An administrator can allow them in Roles & permissions.', 'api_key_not_allowed');
        }

        // MCP is a POST that only dispatches; each call it makes is checked here again.
        if ($request->isMethodSafe() || $request->is('api/*/mcp')) {
            return $next($request);
        }

        if ($level === 'viewer') {
            return $this->refuse('This API key can only read. A Viewer key cannot make changes.', 'api_key_read_only');
        }

        // ponytail: "delete" is the DELETE verb. An endpoint that removes by POST
        // (deactivate, archive) is an edit here; list it if one ever matters.
        if ($level === 'editor' && $request->isMethod('DELETE')) {
            return $this->refuse('This API key can read and edit but not delete. Deleting needs a Full access key.', 'api_key_no_delete');
        }

        return $next($request);
    }

    private function refuse(string $message, string $code): Response
    {
        return response()->json(['message' => $message, 'code' => $code], Response::HTTP_FORBIDDEN);
    }
}
