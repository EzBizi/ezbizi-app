<?php

namespace Tests;

use App\Models\User;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\DatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    /** A 1x1 PNG, the drawn signature every contract signature now needs (2026-10-05). */
    protected const SIGNATURE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(TwilioGateway::class, new FakeTwilioGateway);

        /*
         * A fixture person is an ESTABLISHED one (TODO #22).
         *
         * RequireVerifiedEmail refuses WRITES from an account whose address
         * nobody has confirmed. In production almost nobody is in that state:
         * invited people prove the mailbox by redeeming the invitation link, and
         * seeded and staff accounts are made deliberately. Only self-serve signup
         * creates an unconfirmed account.
         *
         * Tests do not reflect that. Dozens of files build people with a bare
         * User::create in a local helper, each standing in for somebody who has
         * worked there for a year. Left alone, several hundred of them failed on
         * a 403 about email confirmation, which is a fact about the fixture and
         * not about the feature under test - and it masked the refusal each test
         * was actually asserting, which is worse than failing.
         *
         * So: anything created WITHOUT an opinion gets one. Anything that states
         * a value keeps it, which is why ProvisionOrganization writes an explicit
         * null for signup rather than relying on absence. A test that wants an
         * unconfirmed account signs one up through /api/v1/auth/signup, which is
         * the only route that produces one.
         *
         * Test-only, deliberately. The safe default in production is the
         * opposite, and this must never become a reason a real account is
         * confirmed by something nobody wrote down.
         */
        User::creating(function (User $user): void {
            if (! array_key_exists('email_verified_at', $user->getAttributes())) {
                $user->email_verified_at = now();
            }
        });
    }

    /** Booted config includes cached settings and DB_URL; no migration has run yet. */
    protected function setUpTraits()
    {
        $config = $this->app['config'];
        $default = $config->get('database.default');
        $names = [$default];
        if (method_exists($this, 'connectionsToTransact')) {
            $names = [...$names, ...$this->connectionsToTransact()];
        }
        foreach (array_unique($names) as $name) {
            DatabaseSafety::assertSafe(
                $config->get('database.connections.'.($name ?? $default), []),
                PHP_OS_FAMILY === 'Windows',
                getenv('EZBIZI_ALLOW_SHARED_SCHEMA') === '1',
            );
        }

        return parent::setUpTraits();
    }
}
