<?php

namespace App\Http\Middleware;

use App\Support\Auth\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an API key may not do, whatever its owner may.
 *
 * Read-only keys refuse every write — keyed off the verb, like `subscription`
 * and `verified`, so a new endpoint is covered without anybody listing it.
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

        // MCP is a POST that only dispatches; each call it makes is checked here again.
        if (! $request->isMethodSafe() && ! $token->can('*') && ! $request->is('api/*/mcp')) {
            return $this->refuse('This API key is read-only. Create a key with write access to make changes.', 'api_key_read_only');
        }

        return $next($request);
    }

    private function refuse(string $message, string $code): Response
    {
        return response()->json(['message' => $message, 'code' => $code], Response::HTTP_FORBIDDEN);
    }
}
