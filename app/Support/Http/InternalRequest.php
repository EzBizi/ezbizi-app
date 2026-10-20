<?php

namespace App\Support\Http;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One API call made from inside another, as the same person.
 *
 * Through the kernel, with the outer request's own Authorization and view, so
 * every middleware and policy runs exactly as it would for that person — this
 * grants nothing. Used by MCP (call_api), the API key log (before/after GETs)
 * and putting a keyed change back.
 */
final class InternalRequest
{
    public static function send(
        Request $as,
        string $method,
        string $uri,
        ?array $body = null,
        array $headers = [],
        array $attributes = [],
    ): Response {
        $sub = Request::create(
            '/'.ltrim($uri, '/'),
            $method,
            server: ['REMOTE_ADDR' => $as->ip()],
            content: $body === null ? null : json_encode($body),
        );
        $sub->headers->set('Accept', 'application/json');
        $sub->headers->set('Content-Type', 'application/json');
        $sub->headers->set('Authorization', (string) $as->header('Authorization'));
        if ($as->hasHeader('X-EzBizi-View')) {
            $sub->headers->set('X-EzBizi-View', (string) $as->header('X-EzBizi-View'));
        }
        foreach ($headers as $name => $value) {
            $sub->headers->set($name, $value);
        }
        foreach ($attributes as $name => $value) {
            $sub->attributes->set($name, $value);
        }

        // The inner `tenant` middleware forgets the tenant on its way out; run()
        // puts the outer one back. The container's request goes back too.
        // The guards cache whoever they resolved first. A queue worker runs
        // these for one person after another, so when the cached user is not
        // this request's person the guards are reset; otherwise the second
        // person is answered as the first (measured 2026-10-20: Beta's `me`
        // came back as Alpha's in one process). Afterwards the outer person
        // goes back, so the caller carries on as itself.
        $outer = $as->user();
        $cached = app('auth')->guard()->hasUser() ? app('auth')->guard()->user() : null;
        $switch = $cached !== null && $cached->getAuthIdentifier() !== $outer->getAuthIdentifier();
        if ($switch) {
            app('auth')->forgetGuards();
        }

        try {
            return app(TenantContext::class)->run(
                $outer->organization,
                fn () => app(Kernel::class)->handle($sub),
            );
        } finally {
            app()->instance('request', $as);
            if ($switch) {
                app('auth')->forgetGuards();
            }
        }
    }
}
