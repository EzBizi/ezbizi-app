<?php

namespace Tests\Feature;

use App\Support\Settings\SettingsResolver;
use Tests\TestCase;

/** Audit 2026-11-27: a queue worker must not keep one resolver's cache forever. */
class SettingsResolverScopeTest extends TestCase
{
    public function test_one_instance_per_job_not_per_process(): void
    {
        $first = app(SettingsResolver::class);
        $this->assertSame($first, app(SettingsResolver::class));

        // What the queue worker does between jobs.
        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(SettingsResolver::class));
    }
}
