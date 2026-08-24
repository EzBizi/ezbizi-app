<?php

namespace Tests;

use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Base test case — and a hard refusal to run against a real database.
 *
 * ── Why this exists ──────────────────────────────────────────────
 *
 * On 2026-08-06 I dropped the production database by running
 * `php artisan db:wipe --env=testing --force` inside the test sandbox. There was
 * no `.env.testing` file, so `--env=testing` silently fell back to `.env`, and
 * `.env` names the live database. Every table went. The only thing in it was the
 * Lead Developer account, and it went with them.
 *
 * The sandbox at /var/www/ezbizi deliberately holds a copy of the production
 * `.env` — it needs the app key and the mail config — so "the test environment
 * cannot reach production" was never true there. It only ever depended on
 * phpunit.xml exporting DB_DATABASE, which protects `artisan test` and nothing
 * else.
 *
 * So this checks the thing that actually matters, at the moment it matters: no
 * test does anything until the connected database has said, in its own name,
 * that it is a test database. RefreshDatabase migrates and truncates; pointed at
 * the wrong schema it is indistinguishable from a wipe.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * A database whose name does not contain this is not a test database.
     *
     * A name check rather than a whitelist: it survives someone adding a second
     * suite or a developer running against `ezbizi_testing_local`, and it still
     * refuses `ezbizi`.
     */
    private const REQUIRED_MARKER = 'testing';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TwilioGateway::class, new FakeTwilioGateway);

        $database = config('database.connections.'.config('database.default').'.database');

        // In-memory SQLite is a test database by construction.
        if ($database === ':memory:') {
            return;
        }

        if (! str_contains((string) $database, self::REQUIRED_MARKER)) {
            throw new RuntimeException(sprintf(
                "Refusing to run tests against '%s'. The database name must contain '%s'.\n\n".
                "RefreshDatabase migrates and truncates — pointed at a real schema it IS a wipe, ".
                "and that is not a hypothetical: it happened on 2026-08-06.\n\n".
                "Check phpunit.xml's DB_DATABASE, and that you are not running with an --env ".
                "that has no matching .env file (Laravel falls back to .env without saying so).",
                $database,
                self::REQUIRED_MARKER,
            ));
        }
    }
}
