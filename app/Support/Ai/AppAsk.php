<?php

namespace App\Support\Ai;

use App\Models\User;
use App\Support\Http\InternalRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

/**
 * A staff WhatsApp question answered from the app itself (Jose, 2026-10-13:
 * "grok should be able to have access to everything and grant any information
 * needed ( not modify or delete stuff etc, just provide info via whatsapp )").
 *
 * Grok picks up to three GET endpoints, they run as the asker - through the
 * kernel with a two-minute token of their own, so every permission and
 * current-view rule applies - and Grok answers from what came back. Read-only
 * by construction: only GET routes are offered, and anything else is refused
 * before it is dispatched.
 */
final class AppAsk
{
    public const MAX_CALLS = 3;

    private const BLOCKED = ['auth', 'staff', 'mcp', 'me/api-keys', 'me/sessions'];

    private const MAX_CHARS = 12000;

    /** @return string|null the WhatsApp reply, or null when the app holds no answer */
    public static function answer(User $viewer, string $question, int $timeout = 75): ?string
    {
        $started = microtime(true);
        $left = fn () => $timeout - (int) ceil(microtime(true) - $started);
        $today = now('America/New_York');

        $plan = GrokCli::ask(
            'You help a staff member of a home-services business get information from their CRM, EzBizi. '
            ."Today is {$today->toDateString()} ({$today->format('l')}), America/New_York. Pick at most "
            .self::MAX_CALLS.' GET endpoints (path relative to api/v1, with {placeholders} filled in, and '
            .'query parameters such as search, per_page, from, to) that would answer the question. If none '
            ."would, return no calls.\n\nENDPOINTS:\n".self::catalogue()."\n\nQUESTION: ".$question,
            [
                'type' => 'object',
                'properties' => ['calls' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string'], 'query' => ['type' => 'string']],
                    'required' => ['path', 'query'],
                    'additionalProperties' => false,
                ]]],
                'required' => ['calls'],
                'additionalProperties' => false,
            ],
            max(10, min(30, $left() - 25)),
        );

        $calls = array_slice((array) ($plan['calls'] ?? []), 0, self::MAX_CALLS);
        if ($calls === []) {
            return null;
        }

        $results = self::fetch($viewer, $calls);
        if ($results === [] || $left() < 10) {
            return null;
        }

        $a = GrokCli::ask(
            "Answer the staff member's question from this data from their CRM. Only state what the data "
            .'shows; if it does not answer the question, set answered to false. Never invent names, '
            ."numbers or dates.\n".GrokCli::WHATSAPP_STYLE."\n\nQUESTION: {$question}\n\nDATA:\n"
            .json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            [
                'type' => 'object',
                'properties' => ['answered' => ['type' => 'boolean'], 'reply' => ['type' => 'string']],
                'required' => ['answered', 'reply'],
                'additionalProperties' => false,
            ],
            $left() - 5,
        );

        $reply = trim((string) ($a['reply'] ?? ''));

        return ($a['answered'] ?? false) === true && $reply !== '' ? $reply : null;
    }

    /** Every tenant GET endpoint, one per line. */
    public static function catalogue(): string
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteObject $r) => in_array('GET', $r->methods(), true)
                && str_starts_with($r->uri(), 'api/v1/')
                && in_array('tenant', $r->gatherMiddleware(), true)
                && self::allowed(substr($r->uri(), 7)))
            ->map(fn (RouteObject $r) => substr($r->uri(), 7))
            ->unique()->implode("\n");
    }

    /**
     * Runs the GETs as the viewer. A path that is not a tenant GET route is
     * refused here, never dispatched.
     *
     * @param  list<array{path: string, query: string}>  $calls
     * @return array<string, mixed> path => response (or the refusal)
     */
    public static function fetch(User $viewer, array $calls): array
    {
        $token = $viewer->createToken('WhatsApp AI (read only)', ['*'], now()->addMinutes(2));
        $as = Request::create('/');
        $as->headers->set('Authorization', 'Bearer '.$token->plainTextToken);
        $as->setUserResolver(fn () => $viewer);

        try {
            $out = [];
            foreach ($calls as $call) {
                $path = preg_replace('#^/?(api/)?v1/#', '', trim((string) ($call['path'] ?? ''), '/'));
                if (! self::readable($path)) {
                    $out[$path] = 'refused';

                    continue;
                }
                parse_str(ltrim((string) ($call['query'] ?? ''), '?'), $query);
                $response = InternalRequest::send($as, 'GET', '/api/v1/'.$path.($query ? '?'.http_build_query($query) : ''));
                $raw = (string) $response->getContent();
                $out[$path] = $response->getStatusCode() >= 400
                    ? 'HTTP '.$response->getStatusCode()
                    : (strlen($raw) > self::MAX_CHARS ? mb_strcut($raw, 0, self::MAX_CHARS).' …(cut)' : json_decode($raw, true) ?? $raw);
            }

            return $out;
        } finally {
            $token->accessToken->delete();
        }
    }

    private static function readable(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || ! preg_match('#^[A-Za-z0-9_\-./]+$#', $path) || ! self::allowed($path)) {
            return false;
        }
        try {
            $route = Route::getRoutes()->match(Request::create('/api/v1/'.$path, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        return in_array('tenant', $route->gatherMiddleware(), true);
    }

    private static function allowed(string $path): bool
    {
        foreach (self::BLOCKED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }
}
