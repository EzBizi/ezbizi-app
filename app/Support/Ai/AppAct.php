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
 * Grok picks ONE endpoint from the COMMANDS allowlist; the bot repeats it
 * back in plain words and nothing runs until the person answers YES. It then
 * goes through the kernel as them, so the route's own Roles & Permissions
 * tick, its audit and the Automations it raises are exactly the screen's.
 * Who may command at all is the `ai_assistance.command` tick.
 *
 * Grok sees the request and the route list only. No response body is ever
 * sent to it, so AppAsk's GET/JSON privacy line is untouched.
 *
 * A chat command does NOT ask for the app's second factor. The proof of who
 * is asking is WhatsApp's: the message came from the phone number on that
 * person's EzBizi profile, through Meta's signed webhook. Anyone holding that
 * unlocked phone can command as them, within the allowlist below.
 */
final class AppAct
{
    public const PERMISSION = 'ai_assistance.command';

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

        $body = (array) (json_decode((string) ($p['body'] ?? ''), true) ?: []);

        if (! self::writable($method, $path, $body) || trim((string) ($p['summary'] ?? '')) === '') {
            return null;
        }

        return [
            'method' => $method,
            'path' => $path,
            'body' => $body,
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

        if (! $viewer->hasPermission(self::PERMISSION) || ! self::writable($plan['method'], $plan['path'], $plan['body'])) {
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

    /**
     * The ONLY routes a chat command can reach: "METHOD uri template". An
     * allowlist, so a route added later is refused until its module opts in
     * here. Jose's asks first (desk, 2026-10-16).
     */
    public const COMMANDS = [
        'PUT reviews/{review}/reply' => null,
        'POST reviews/{review}/reply/publish' => null,
        'POST scheduling/board/jobs/{job}/reschedule' => null,
        // Status only: the star and note on a lead are not changed from chat.
        'PATCH leads/{lead}' => ['status'],
        'PUT scheduling/locations/notes/{company}' => null,
    ];

    /**
     * Never commandable, even if a module adds it above: keys, connections,
     * exports, staff, roles, billing, mass sends, automations on/off, and
     * anything touching who can sign in. Matched against the template.
     */
    private const NEVER = '#(^|/)(auth|staff|mcp|api-keys|tokens?|sessions|password|two-factor|impersonat\w*|roles?|permissions|billing|subscription|integrations|social/connections|connect|disconnect|webhooks?|exports?|downloads?|newsletters?|bulk|broadcasts?|automations)(/|$)#';

    /** The allowed routes, one per line, for Grok. */
    public static function catalogue(): string
    {
        return implode('
', array_filter(array_keys(self::COMMANDS), fn ($c) => ! self::forbidden(explode(' ', $c, 2)[1])));
    }

    /** A real tenant route, on the allowlist, and not forbidden. */
    public static function writable(string $method, string $path, array $body = []): bool
    {
        if ($path === '') {
            return false;
        }

        $route = collect(Route::getRoutes()->get($method) ?? [])
            ->first(fn (RouteObject $r) => $r->matches(Request::create('/api/v1/'.$path, $method)));

        if ($route === null || ! in_array('tenant', $route->gatherMiddleware(), true)) {
            return false;
        }

        $template = substr($route->uri(), 7);

        $command = $method.' '.$template;
        if (! array_key_exists($command, self::COMMANDS) || self::forbidden($template)) {
            return false;
        }

        // A command limited to some fields may carry no others.
        $keys = self::COMMANDS[$command];

        return $keys === null || array_diff(array_keys($body), $keys) === [];
    }

    public static function forbidden(string $template): bool
    {
        return preg_match(self::NEVER, $template) === 1;
    }

    private static function key(User $viewer): string
    {
        return 'wa-command:'.$viewer->getKey();
    }
}
