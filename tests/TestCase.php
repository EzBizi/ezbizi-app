<?php

namespace Tests;

use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\DatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(TwilioGateway::class, new FakeTwilioGateway);
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
