<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retries a request MySQL refused with a deadlock (1213 / SQLSTATE 40001).
 *
 * Placed in FRONT of `throttle` on the agent routes: prod's limiter lives in
 * the database cache store, and its `insert ignore into cache (…:timer)`
 * deadlocked 12 times on 2026-11-25, 8 of them as 500s on the heartbeat.
 * The limiter writes before the controller runs, so a retry repeats nothing
 * the request already did.
 * ponytail: retry, not a new limiter store (the box has no redis/apcu); move
 * the limiter off the database if deadlocks show up outside these routes.
 */
class RetryOnDeadlock
{
    private const ATTEMPTS = 3;

    public function handle(Request $request, Closure $next): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $next($request);
            } catch (QueryException $e) {
                if ($attempt >= self::ATTEMPTS || ! self::isDeadlock($e)) {
                    throw $e;
                }
                usleep(random_int(20, 80) * 1000);

                continue;
            }

            // Inside the router the exception never reaches here as a throw:
            // the routing pipeline renders it into a 500 first and hangs it on
            // the response. Reading it there is what makes the retry real.
            $thrown = $response->exception ?? null;

            if ($thrown instanceof QueryException && self::isDeadlock($thrown) && $attempt < self::ATTEMPTS) {
                usleep(random_int(20, 80) * 1000);

                continue;
            }

            return $response;
        }
    }

    public static function isDeadlock(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1213 || $e->getCode() === '40001';
    }
}
