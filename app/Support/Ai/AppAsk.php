<?php

namespace App\Support\Ai;

use App\Models\User;
use App\Support\Http\InternalRequest;
use App\Support\Scoping\ViewScope;
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
        return self::answerWithMedia($viewer, $question, $timeout)['reply'] ?? null;
    }

    /**
     * The reply, and the photo or video links to send after it (a tech asking for
     * before/after photos or a training clip, Jose 2026-11-09).
     *
     * @return array{reply: string, media: list<string>}|null
     */
    public static function answerWithMedia(User $viewer, string $question, int $timeout = 75): ?array
    {
        // Traced as the asker's (GrokCli::about): their question and the data read as them.
        return GrokCli::about($viewer, fn () => self::answerAs($viewer, $question, $timeout));
    }

    /**
     * Only a link the asker's own reads returned, and only a photo or an mp4:
     * the model can name a link, it cannot widen what the person may see.
     *
     * @return list<string>
     */
    public static function mediaFrom(mixed $media, string $data): array
    {
        return array_values(array_slice(array_unique(array_filter((array) $media, fn ($u) => is_string($u)
            && preg_match('~^https://\S+\.(jpe?g|png|mp4)$~i', $u) === 1
            && str_contains($data, $u))), 0, 4));
    }

    /** @return array{reply: string, media: list<string>}|null */
    private static function answerAs(User $viewer, string $question, int $timeout): ?array
    {
        $started = microtime(true);
        $left = fn () => $timeout - (int) ceil(microtime(true) - $started);
        $today = now(\App\Support\Settings\OrgTimezone::of());
        $call = [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'query' => ['type' => 'string']],
            'required' => ['path', 'query'],
            'additionalProperties' => false,
        ];
        // An assistant, not a menu (Jose, 2026-11-05: "allow it free reign to look at
        // information ... depending on who talks to it"): it reads as many rounds as it
        // needs, as the asker, and always says something - small talk included.
        $about = 'You are the EzBizi assistant for a staff member of a home-services business, '
            .'with read access to their CRM as them (their permissions apply). You have NO tools and cannot '
            .'read code or files: you read the CRM only by asking for GET paths from the list. '
            .self::business($viewer, true)
            ."Today is {$today->toDateString()} ({$today->format('l')}), {$today->getTimezone()->getName()}. "
            .'A path is relative to api/v1 with {placeholders} filled in; query parameters use only the '
            .'names in brackets after each path, as a query string such as when=upcoming&today=2026-10-15. '
            .'Search broadly: a phone number or name can be a customer, a lead, a call or a job; a company '
            .'or branch means its id; "all companies" means each of them. '
            // A technician's field questions (Jose, 2026-11-09), answered from what exists, never invented.
            .'For a technician: before/after photos of previous jobs come from jobs/photos (put up to 4 of '
            .'its photo links in media, say job type and city, never a customer); "how to sell this" is a '
            .'short speech, the CS code and price from services, the best solution and the consequences of '
            .'not doing it, from guidelines and training/learn/videos; "how to do" is the steps from guidelines '
            .'or training/learn/videos (search one or two key words, e.g. search=crown) with each matching '
            .'video link in the reply, and a direct .mp4 link also in media; a closing example comes from guidelines; when '
            .'none is there, close in one line: the value to them, then ask for the yes now ("Shall I add it to today\'s visit?"). '
            // A dispatcher's questions (Jose's board, "Dispatch Support Bot", 2026-11-20).
            .'For a dispatcher: how to handle a call, a policy, a script or an objection comes from guidelines (name the '
            .'guideline it came from); a price comes from services with its code; never quote a price or a promise the data does not hold. '
            .'Whatever the data does not hold, say so instead of making it up. ';

        // One retry: the CLI now and then stops on "max turns reached" (prod 11-07, leads this week).
        $ask = function (string $prompt, array $schema, int $timeout) use ($left) {
            try {
                return GrokCli::ask($prompt, $schema, $timeout);
            } catch (\RuntimeException $e) {
                if ($left() < 25) {
                    throw $e;
                }

                return GrokCli::ask($prompt, $schema, min($timeout, $left() - 10));
            }
        };

        $plan = $ask(
            $about.'Pick up to '.self::MAX_CALLS.' paths to start with. If the message needs no data '
            .'(a greeting, thanks, a general question), return no calls and write the reply.'
            ."\n".GrokCli::WHATSAPP_STYLE."\n\nENDPOINTS:\n".self::catalogue()."\n\nQUESTION: ".$question,
            [
                'type' => 'object',
                'properties' => ['calls' => ['type' => 'array', 'items' => $call], 'reply' => ['type' => 'string']],
                'required' => ['calls'],
                'additionalProperties' => false,
            ],
            max(10, min(30, $left() - 25)),
        );

        $calls = array_slice((array) ($plan['calls'] ?? []), 0, self::MAX_CALLS);
        if ($calls === []) {
            $reply = trim((string) ($plan['reply'] ?? ''));

            return $reply === '' ? null : ['reply' => $reply, 'media' => []];
        }

        // ponytail: 4 rounds of 3 reads; more when an answer measurably needs them.
        $data = [];
        for ($round = 0; $round < 4; $round++) {
            // Keyed with the query: the same path read per company is several answers.
            foreach ($calls as $c) {
                $q = ltrim((string) ($c['query'] ?? ''), '?');
                $data[trim((string) ($c['path'] ?? ''), '/').($q === '' ? '' : '?'.$q)] = current(self::fetch($viewer, [$c]));
            }
            if ($left() < 15) {
                break;
            }
            $last = $round === 3 || $left() < 55;   // the answer over a full DATA needs ~25 s (prod 11-05: 19 s timed out)
            $a = $ask(
                $about.'Answer from the DATA below. Never invent names, numbers or dates; if the data '
                .'only partly answers, say what it shows and what it does not. '
                .($last ? '' : 'If you need more data first (a failed read, another company, a different '
                    .'filter, the next page), return up to '.self::MAX_CALLS.' more calls from the PATHS list '
                    .'and leave reply empty. ')
                ."\n".GrokCli::WHATSAPP_STYLE."\n\nQUESTION: {$question}\n\n"
                .($last ? '' : "PATHS:\n".self::catalogue()."\n\n")
                // ponytail: 40k chars of DATA; a fuller read took 53 s and timed out (prod 11-07).
                ."DATA:\n".mb_substr(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 40000),
                [
                    'type' => 'object',
                    'properties' => ['reply' => ['type' => 'string'], 'calls' => ['type' => 'array', 'items' => $call],
                        'media' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    'required' => ['reply'],
                    'additionalProperties' => false,
                ],
                // A round that may ask for more leaves time for the answer after it.
                max(10, $last ? $left() - 5 : min(30, $left() - 30)),
            );
            $reply = trim((string) ($a['reply'] ?? ''));
            $calls = $last ? [] : array_slice((array) ($a['calls'] ?? []), 0, self::MAX_CALLS);
            if ($calls === []) {
                return $reply === '' ? null : ['reply' => $reply, 'media' => self::mediaFrom($a['media'] ?? [], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
            }
        }

        return null;
    }

    /**
     * The companies and branches this person reaches, so a question naming
     * one ("tomorrow at a-1 chimney") maps onto the branches the data lists
     * by their own names (prod, 2026-10-26: Grok found no branch called
     * "A-1 Chimney" and refused, though every A-1 branch was in the data).
     */
    private static function business(User $viewer, bool $ids): string
    {
        $lines = $viewer->reachableCompanies()->sortBy('name')
            ->map(function ($company) use ($viewer, $ids) {
                // With ids: two companies can each have a "Connecticut" (A-1's and
                // Everdoors', prod 2026-10-26); the data's franchise_id tells them apart.
                $branches = $viewer->reachableFranchises()->where('company_id', $company->id)->map(fn ($f) => $ids ? "{$f->name} [{$f->id}]" : $f->name);

                // The planner needs the company's id for company_id (prod 2026-11-04: "jobs created
                // yesterday in ace fireplace" was planned without it and answered from A-1's jobs).
                return '- '.$company->name.($ids ? " [{$company->id}]" : '').($branches->isEmpty() ? '' : ': '.$branches->implode(', '));
            })->take(60)->implode("\n");

        return $lines === '' ? '' : 'Their companies and the branches (franchises) of each - a question naming a '
            ."company means all of its branches together - lead with the total, then each branch "
            ."(a board's counts.by_branch has them):\n{$lines}\n\n";
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
        // Marked as the AI's: a sensitive task is never shown to it (SensitiveTasks).
        $token = $viewer->createToken('WhatsApp AI (read only)', ['*', \App\Support\Tasks\SensitiveTasks::AI_READER], now()->addMinutes(2));
        $as = Request::create('/');
        $as->headers->set('Authorization', 'Bearer '.$token->plainTextToken);
        $as->setUserResolver(fn () => $viewer);
        // The asker's whole reach, not the view their screen last stood in: a
        // chat has no current view, and Jose's stored view (one company) read
        // tomorrow's board as empty (prod, 2026-10-26). ViewScope resolves
        // "organization" to the highest view they reach, never above it.
        $as->headers->set('X-EzBizi-View', ViewScope::ORGANIZATION);

        try {
            $out = [];
            foreach ($calls as $call) {
                $path = preg_replace('#^/?(api/)?v1/#', '', trim((string) ($call['path'] ?? ''), '/'));
                if (! self::readable($path)) {
                    $out[$path] = 'refused';

                    continue;
                }
                parse_str(ltrim((string) ($call['query'] ?? ''), '?'), $query);
                $response = InternalRequest::send($as, 'GET', '/api/v1/'.$path.($query ? '?'.http_build_query($query) : ''),
                    attributes: [\App\Support\Tasks\SensitiveTasks::AI_READER => true]);
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

    /**
     * For a cut list of rows: how many per value of each short text field
     * (branch, status, window...), so "how many at A-1" can still be counted
     * after the rows themselves were cut (prod, 2026-10-26: Grok gave the
     * board's 42 for every branch where A-1's own branches held 37).
     *
     * @return array<string, array<string, int>>
     */
    private static function countsBy(array $rows): array
    {
        $fields = [];
        foreach ($rows as $row) {
            foreach (is_array($row) ? $row : [] as $key => $v) {
                if (is_string($v) && mb_strlen($v) <= 60 && ($key === 'franchise_id' || ! str_ends_with((string) $key, 'id'))) {
                    $fields[$key][$v] = ($fields[$key][$v] ?? 0) + 1;
                }
            }
        }

        // ponytail: fields with few distinct values only; ids (but the branch's), names and addresses are not groupings.
        return array_filter($fields, fn ($counts) => count($counts) > 1 && count($counts) <= 25);
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
            return ['_total' => count($value), '_counts' => self::countsBy($value),
                '_first' => array_map(fn ($v) => self::shrink($v, $keep), array_slice($value, 0, $keep))];
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
