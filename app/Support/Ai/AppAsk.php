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
 * before it is dispatched. Jose, 2026-10-13: "Everything, as asked" - Google
 * data and older records included, on this staff Q&A path only.
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

        $plan = fn (string $retry) => GrokCli::ask(
            // The CLI is an agent: without this line it planned "grep" calls on the codebase (prod, 2026-10-14).
            'You have NO tools and cannot read code or files: only choose from the ENDPOINTS list below. '
            .'You help a staff member of a home-services business get information from their CRM, EzBizi. '
            ."Today is {$today->toDateString()} ({$today->format('l')}), America/New_York. Pick at most "
            .self::MAX_CALLS.' GET endpoints (path relative to api/v1, with {placeholders} filled in, and '
            .'query parameters, using only the names listed in brackets after each endpoint, as a query '
            .'string such as when=upcoming&today=2026-10-15) that would answer the question. If none '
            ."would, return no calls.\n\nENDPOINTS:\n".self::catalogue()."\n\nQUESTION: ".$question.$retry,
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

        // One retry when every call failed: the app's own error ("The selected
        // when is invalid.") tells Grok which value to change (prod, 2026-10-14).
        $retry = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $calls = array_slice((array) ($plan($retry)['calls'] ?? []), 0, self::MAX_CALLS);
            if ($calls === []) {
                return null;
            }
            $results = self::fetch($viewer, $calls);
            $failed = array_filter($results, fn ($r) => is_string($r) && str_starts_with($r, 'HTTP '));
            if (count($failed) < count($results) || $left() < 35) {
                break;
            }
            $retry = "\n\nYOUR LAST CALLS FAILED - fix the query and try again:\n"
                .json_encode(array_map(null, $calls, array_values($results)), JSON_UNESCAPED_SLASHES);
        }
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
            ->map(fn (RouteObject $r) => substr($r->uri(), 7).self::params($r))
            ->unique()->implode("\n");
    }

    /**
     * The query names a route's action reads, as " [a, b]": the keys of its
     * validate() rules and query('x') reads, found in the method's source.
     * ponytail: a regex over source, not a schema; a route that reads its
     * query some other way shows none, and Grok guesses.
     */
    private static function params(RouteObject $route): string
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return '';
        }
        try {
            $m = new \ReflectionMethod(...explode('@', $action, 2));
            $src = implode('', array_slice(file((string) $m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        } catch (\Throwable) {
            return '';
        }
        preg_match_all("/(?:'([a-z_]+)'\\s*=>\\s*\\[|(?:query|input|integer|boolean|string|date)\\('([a-z_]+)')/", $src, $mm);
        $names = array_values(array_unique(array_filter(array_merge($mm[1], $mm[2]))));

        // A rule's fixed choices go with the name: when=upcoming|past|all.
        // Without them Grok guessed when=tomorrow and sort=created_at (prod, 2026-10-14).
        $names = array_map(function (string $name) use ($src, $m) {
            if (! preg_match("/'{$name}'\\s*=>\\s*\\[[^\\n]*Rule::in\\((.+?)\\)\\s*[\\],]/", $src, $rule)) {
                return $name;
            }
            $values = [];
            if (preg_match('/self::([A-Z_]+)/', $rule[1], $const) && $m->getDeclaringClass()->hasConstant($const[1])) {
                $list = (array) $m->getDeclaringClass()->getConstant($const[1]);
                $values = str_contains($rule[1], 'array_keys') ? array_keys($list) : array_values($list);
            } elseif (preg_match_all("/'([^']+)'/", $rule[1], $quoted)) {
                $values = $quoted[1];
            }
            $values = array_filter($values, 'is_scalar');

            return $values === [] ? $name : $name.'='.implode('|', array_slice($values, 0, 10));
        }, $names);

        return $names === [] ? '' : ' ['.implode(', ', array_slice($names, 0, 12)).']';
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
                // Only JSON reaches Grok: audio, files and pages (an LSA recording) never do.
                $json = str_contains((string) $response->headers->get('Content-Type'), 'json');
                $out[$path] = $response->getStatusCode() >= 400
                    ? 'HTTP '.$response->getStatusCode().($response->getStatusCode() === 422 ? ': '.mb_substr($raw, 0, 400) : '')
                    : ($json ? self::cut(json_decode($raw, true) ?? $raw) : 'not readable here');
            }

            return $out;
        } finally {
            $token->accessToken->delete();
        }
    }

    /** Jose, 2026-10-13: signed links (customer links, signature=) never reach xAI. */
    public static function withoutSignedLinks(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace('#https?://\S*[?&]signature=\S*#i', '[signed link removed]', $value);
        }
        if (is_array($value)) {
            array_walk_recursive($value, function (&$v) {
                $v = is_string($v) ? self::withoutSignedLinks($v) : $v;
            });
        }

        return $value;
    }

    private static function cut(mixed $value): mixed
    {
        $value = self::withoutSignedLinks($value);
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Too big: keep the shape and every list's length rather than the first
        // 12 KB of text - "how many jobs tomorrow" is the length of a list the
        // cut used to drop (the board for one day, prod, 2026-10-14).
        foreach ([20, 5, 1] as $keep) {
            if (strlen($json) <= self::MAX_CHARS) {
                break;
            }
            $value = self::shrink($value, $keep);
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return strlen($json) > self::MAX_CHARS ? mb_strcut($json, 0, self::MAX_CHARS).' …(cut)' : $value;
    }

    /** Long lists keep $keep items plus their total; long text is trimmed. */
    public static function shrink(mixed $value, int $keep): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 200 ? mb_substr($value, 0, 200).'…' : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value) && count($value) > $keep) {
            return ['_total' => count($value), '_first' => array_map(fn ($v) => self::shrink($v, $keep), array_slice($value, 0, $keep))];
        }

        return array_map(fn ($v) => self::shrink($v, $keep), $value);
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
