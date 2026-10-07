<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\McpController;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\ApiKey;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
    private const MAX_BODY = 200000;

    /** Marks the internal GET that snapshots a record, so it is not logged as the key's own read. */
    private const SNAPSHOT = 'api_key.snapshot';

    private const ACCOUNT_SECURITY = [
        'api/*/me/password',
        'api/*/me/totp', 'api/*/me/totp/*',
        'api/*/me/sms-codes', 'api/*/me/sms-codes/*',
        'api/*/me/development/*',
        'api/*/me/sessions',
        'api/*/me/api-keys', 'api/*/me/api-keys/*',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! ApiKey::is($token)) {
            return $next($request);
        }

        $refusal = $this->refusal($request, $token);
        $before = $refusal === null ? $this->snapshot($request) : null;
        $response = $refusal ?? $next($request);

        $this->record($request, $token, $response, $before);

        return $response;
    }

    /**
     * Every call a key makes, refused ones included (Jose, 2026-10-07: "we should
     * probably have an MCP-API log to record the actions"). Into the audit trail,
     * which is append-only, so a key cannot tidy away what it did.
     *
     * A write keeps what was SENT. What it replaced is the module's own audit
     * row, where the module writes one. The outer POST /mcp is not recorded: each
     * call it dispatches comes back through here with its real path.
     */
    private function record(Request $request, PersonalAccessToken $token, Response $response, ?string $before): void
    {
        if ($request->is('api/*/mcp') || $request->attributes->get(self::SNAPSHOT)) {
            return;
        }

        $write = ! $request->isMethodSafe();
        $body = $write ? json_encode($request->except(['password', 'password_confirmation']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $this->audit->log('api_key.request', $token, changes: $write ? array_filter([
            'body' => $this->cap($body),
            'before' => $this->cap($before),
        ]) : [], metadata: [
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->getQueryString(),
            'status' => $response->getStatusCode(),
            'via' => $request->userAgent() === McpController::USER_AGENT ? 'mcp' : 'api',
            'level' => ApiKey::levelOf($token),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * What the record looked like before a change, so it can be put back (Jose,
     * 2026-10-07: "so changes can be reverted if needed").
     *
     * The GET of the same path, asked as the same person in the same view —
     * `PUT customers/7` is preceded by `GET customers/7`, `PUT web/sites/x/values`
     * by `GET web/sites/x/values`. A create has nothing before it, and a write
     * with no GET twin keeps only what was sent.
     */
    private function snapshot(Request $request): ?string
    {
        if (! in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $get = Request::create('/'.$request->path().($request->getQueryString() ? '?'.$request->getQueryString() : ''), 'GET');

        try {
            Route::getRoutes()->match($get);
        } catch (Throwable) {
            return null;
        }

        $get->headers->set('Accept', 'application/json');
        $get->headers->set('Authorization', (string) $request->header('Authorization'));
        if ($request->hasHeader('X-EzBizi-View')) {
            $get->headers->set('X-EzBizi-View', (string) $request->header('X-EzBizi-View'));
        }
        $get->attributes->set(self::SNAPSHOT, true);

        try {
            $response = app(TenantContext::class)->run(
                $request->user()->organization,
                fn () => app(Kernel::class)->handle($get),
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            app()->instance('request', $request);
        }

        return $response->isSuccessful() ? (string) $response->getContent() : null;
    }

    private function cap(?string $text): ?string
    {
        return $text === null || strlen($text) <= self::MAX_BODY ? $text : substr($text, 0, self::MAX_BODY).'…';
    }

    private function refusal(Request $request, PersonalAccessToken $token): ?Response
    {
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
            return null;
        }

        if ($level === 'viewer') {
            return $this->refuse('This API key can only read. A Viewer key cannot make changes.', 'api_key_read_only');
        }

        // ponytail: "delete" is the DELETE verb. An endpoint that removes by POST
        // (deactivate, archive) is an edit here; list it if one ever matters.
        if ($level === 'editor' && $request->isMethod('DELETE')) {
            return $this->refuse('This API key can read and edit but not delete. Deleting needs a Full access key.', 'api_key_no_delete');
        }

        return null;
    }

    private function refuse(string $message, string $code): Response
    {
        return response()->json(['message' => $message, 'code' => $code], Response::HTTP_FORBIDDEN);
    }
}
