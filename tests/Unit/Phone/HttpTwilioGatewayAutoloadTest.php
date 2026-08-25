<?php

namespace Tests\Unit\Phone;

use App\Support\Phone\HttpTwilioGateway;
use ReflectionClass;
use Tests\TestCase;

/**
 * Production resolves the HTTP gateway by class name. PSR-4 looks for
 * HttpTwilioGateway.php — not a second class living in TwilioGateway.php.
 * Tests hid that: they bind FakeTwilioGateway first, which loads the
 * interface file and defined both classes in one go.
 */
class HttpTwilioGatewayAutoloadTest extends TestCase
{
    public function test_the_http_gateway_lives_in_its_own_psr4_file(): void
    {
        $path = (new ReflectionClass(HttpTwilioGateway::class))->getFileName();

        $this->assertSame(
            'HttpTwilioGateway.php',
            basename((string) $path),
            'App\\Support\\Phone\\HttpTwilioGateway must be autoloadable as its own file. '
            .'On production the container reflects that class name before the interface file is loaded.',
        );
    }
}
