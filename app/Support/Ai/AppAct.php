<?php

namespace App\Support\Ai;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Http\InternalRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * A staff WhatsApp command ("publish the reply to the Smith review") run as
 * the sender (A1 Leads takeover, 2026-10-16).
 *
 * Grok picks ONE write endpoint the screens already use; the bot repeats it
 * back in plain words and nothing runs until the person answers YES. It then
 * goes through the kernel as them, so the route's own Roles & Permissions
 * tick, its audit and the Automations it raises are exactly the screen's.
 * Who may command at all is the `ai_assistance.command` tick.
 *
 * Grok sees the request and the route list only. No response body is ever
 * sent to it, so AppAsk's GET/JSON privacy line is untouched. DELETE routes
 * are never offered: a removal is done on the screen.
 */
final class AppAct
{
    public const PERMISSION = 'ai_assistance.command';

    private const BLOCKED = ['auth', 'staff', 'mcp', 'me/api-keys', 'me/sessions', 'me/password', 'roles', 'billing'];

    private const TTL_MINUTES = 10;

    /** @return array{method: string, path: string, body: array, summary: string}|null */
    public static function plan(string $request, int $timeout = 60): ?array
    {
        $today = now('America/New_York');
        $p = GrokCli::ask(
            'You have NO tools and cannot read code or files: only choose from the ENDPOINTS list below. '
            .'A staff member of a home-services business asked their CRM, EzBizi, to DO something. '
            ."Today is {$today->toDateString()} ({$today->format('l')}), America/New_York. Pick the ONE "
            .'endpoint (method, path relative to api/v1 with {placeholders} filled in, JSON body) that '
            .'does it, and a one-line summary in plain words of exactly what will change. If no endpoint '
            .'does it, or you would have to guess an id or a value, return method "".'
            ."\n\nENDPOINTS:\n".self::catalogue()."\n\nREQUEST: ".$request,
            [
                'type' => 'object',
                'properties' => [
                    'method' => ['type' => 'string'],
                    'path' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['method', 'path', 'body', 'summary'],
                'additionalProperties' => false,
            ],
            $timeout,
        );

        $method = strtoupper(trim((string) ($p['method'] ?? '')));
        $path = preg_replace('#^/?(api/)?v1/#', '', trim((string) ($p['path'] ?? ''), '/'));

        if (! self::writable($method, $path) || trim((string) ($p['summary'] ?? '')) === '') {
            return null;
        }

        return [
            'method' => $method,
            'path' => $path,
            'body' => (array) (json_decode((string) ($p['body'] ?? ''), true) ?: []),
            'summary' => trim((string) $p['summary']),
        ];
    }

    /** Held until YES, per sender, and only for TTL_MINUTES. */
    public static function hold(User $viewer, array $plan): void
    {
        Cache::put(self::key($viewer), $plan, now()->addMinutes(self::TTL_MINUTES));
    }

    public static function pending(User $viewer): ?array
    {
        return Cache::get(self::key($viewer));
    }

    public static function drop(User $viewer): void
    {
        Cache::forget(self::key($viewer));
    }

    /**
     * Runs the held command as the viewer. Re-checked here, not trusted from
     * the plan: the tick or the route may have changed in the ten minutes.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public static function run(User $viewer, array $plan): array
    {
        self::drop($viewer);

        if (! $viewer->hasPermission(self::PERMISSION) || ! self::writable($plan['method'], $plan['path'])) {
            return ['ok' => false, 'status' => 403, 'message' => 'That is not something you can do from WhatsApp.'];
        }

        $token = $viewer->createToken('WhatsApp command', ['*'], now()->addMinutes(2));
        $as = Request::create('/');
        $as->headers->set('Authorization', 'Bearer '.$token->plainTextToken);
        $as->setUserResolver(fn () => $viewer);

        try {
            $response = InternalRequest::send($as, $plan['method'], '/api/v1/'.$plan['path'], $plan['body']);
        } finally {
            $token->accessToken->delete();
        }

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getContent(), true);

        // The route audits its own change; this records that it came from chat.
        app(AuditLogger::class)->log('staff_bot.command', metadata: [
            'method' => $plan['method'],
            'path' => $plan['path'],
            'summary' => $plan['summary'],
            'status' => $status,
        ], actor: $viewer);

        return [
            'ok' => $status < 400,
            'status' => $status,
            'message' => is_array($json) ? (string) ($json['message'] ?? '') : '',
        ];
    }

    /** Every tenant write endpoint (POST/PUT/PATCH), one per line. */
    public static function catalogue(): string
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteObject $r) => str_starts_with($r->uri(), 'api/v1/')
                && in_array('tenant', $r->gatherMiddleware(), true))
            ->flatMap(fn (RouteObject $r) => collect($r->methods())
                ->filter(fn ($m) => self::offered($m, substr($r->uri(), 7)))
                ->map(fn ($m) => $m.' '.substr($r->uri(), 7)))
            ->unique()->implode("\n");
    }

    public static function writable(string $method, string $path): bool
    {
        if (! self::offered($method, $path)) {
            return false;
        }

        // Must be a real tenant route for that method: a path Grok made up never runs.
        $route = collect(Route::getRoutes()->get($method) ?? [])
            ->first(fn (RouteObject $r) => $r->matches(Request::create('/api/v1/'.$path, $method)));

        return $route !== null && in_array('tenant', $route->gatherMiddleware(), true);
    }

    private static function offered(string $method, string $path): bool
    {
        if (! in_array($method, ['POST', 'PUT', 'PATCH'], true) || $path === '') {
            return false;
        }

        foreach (self::BLOCKED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }

    private static function key(User $viewer): string
    {
        return 'wa-command:'.$viewer->getKey();
    }
}
