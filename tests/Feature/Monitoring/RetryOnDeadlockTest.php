<?php

namespace Tests\Feature\Monitoring;

use App\Http\Middleware\RetryOnDeadlock;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

/** 2026-11-25: 12 limiter-timer deadlocks, 8 heartbeat 500s. */
class RetryOnDeadlockTest extends TestCase
{
    private static function deadlock(): QueryException
    {
        $pdo = new PDOException('Deadlock found when trying to get lock', 40001);
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        return new QueryException('mysql', 'insert ignore into cache ...', [], $pdo);
    }

    public function test_a_deadlock_is_retried_and_the_request_answers(): void
    {
        $calls = 0;
        $response = (new RetryOnDeadlock)->handle(Request::create('/x'), function () use (&$calls) {
            if (++$calls === 1) {
                throw self::deadlock();
            }

            return new Response('ok');
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertSame(2, $calls);
    }

    /**
     * Through the router, where it actually runs (audit 2026-11-27): the
     * routing pipeline renders an exception into a 500 before it reaches the
     * middleware as a throw, so a catch alone never saw a deadlock.
     */
    public function test_a_deadlock_inside_a_route_is_retried(): void
    {
        $calls = 0;

        Route::post('/_test/retry-on-deadlock', function () use (&$calls) {
            if (++$calls === 1) {
                throw self::deadlock();
            }

            return response('ok');
        })->middleware('retry-deadlock');

        $this->post('/_test/retry-on-deadlock')->assertOk()->assertSeeText('ok');
        $this->assertSame(2, $calls);
    }

    public function test_any_other_query_error_is_not_retried(): void
    {
        $calls = 0;
        $this->expectException(QueryException::class);
        (new RetryOnDeadlock)->handle(Request::create('/x'), function () use (&$calls) {
            $calls++;
            throw new QueryException('mysql', 'x', [], new PDOException('nope', 23000));
        });
    }

    public function test_the_retry_wraps_the_throttle_on_the_heartbeat_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/monitoring/heartbeat', 'POST'));
        $order = app('router')->gatherRouteMiddleware($route);
        $retry = array_search(RetryOnDeadlock::class, $order, true);
        $throttle = collect($order)->search(fn ($m) => str_contains($m, 'ThrottleRequests'));

        $this->assertNotFalse($retry);
        $this->assertNotFalse($throttle);
        $this->assertLessThan($throttle, $retry, 'the retry must run outside the limiter that deadlocks');
    }
}
