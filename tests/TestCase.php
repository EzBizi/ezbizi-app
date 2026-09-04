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

    /**
     * The shared schema every session lands on when nobody types the prefix.
     */
    private const SHARED_SCHEMA = 'ezbizi_testing';

    protected function setUp(): void
    {
        // BEFORE parent::setUp(), which is where RefreshDatabase drops and
        // remigrates the schema. Checking after it would fire the warning having
        // already done the exact damage it exists to prevent.
        $this->refuseSharedSchema();

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

    /**
     * A rule that executes, because the same rule in prose did not.
     *
     * Sixteen sessions share this Windows machine and one MySQL. `RefreshDatabase`
     * drops the schema between tests, so two sessions running at once destroy each
     * other's runs. ACTIVE-CONSTRAINTS §4 has said so for a week; two sessions lost
     * a full run to it anyway, and BOTH had read the file. That is a placement
     * problem, not a wording one — the rule was true and it was nowhere near the
     * moment it mattered.
     *
     * THE COLLISION DOES NOT LOOK LIKE A COLLISION, which is what makes it expensive.
     * It surfaces as 150 errors spread across TenantIsolation, TimeClock, Inspection
     * and Web Management — indistinguishable from having broken the tenant boundary, so
     * the natural response is to go debugging the security model. One session went
     * 153 red to 17 on the prefix alone; another went 107 to 112/112.
     *
     * Windows only, deliberately. The deploy gate runs on its own Linux sandbox
     * where `ezbizi_testing` has exactly one tenant, and refusing there would break
     * the thing that gates every release, for no gain.
     */
    private function refuseSharedSchema(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        // getenv, not config(): the app is not booted yet, and this has to run
        // before it is. phpunit.xml sets DB_DATABASE without force="true", so a
        // real shell variable wins and getenv reports whichever actually applies.
        if (getenv('DB_DATABASE') !== self::SHARED_SCHEMA) {
            return;
        }

        // Deliberate single-session use stays possible, and has to say so out loud.
        if (getenv('EZBIZI_ALLOW_SHARED_SCHEMA') === '1') {
            return;
        }

        throw new RuntimeException(sprintf(
            "Refusing to run against the SHARED '%s' schema on this machine.

".
            'Sixteen sessions share one MySQL here, and RefreshDatabase drops this '.
            'schema between tests. If anyone else is running, you will both get a '.
            "wall of red that looks like a broken tenant boundary and is not.

".
            "Use your own:
".
            "    DB_DATABASE=%s_<yourmodule> vendor/bin/phpunit
".
            "    DB_DATABASE=%s_<yourmodule> php artisan test

".
            'Anything that is NOT phpunit needs DB_CONNECTION=mysql as well, or '.
            'Laravel reads DB_DATABASE as a sqlite FILE PATH and exits 0 having '.
            "touched no MySQL at all.

".
            'Genuinely alone and want the shared one? EZBIZI_ALLOW_SHARED_SCHEMA=1',
            self::SHARED_SCHEMA,
            self::SHARED_SCHEMA,
            self::SHARED_SCHEMA,
        ));
    }
}
