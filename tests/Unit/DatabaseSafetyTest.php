<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DatabaseSafety;

class DatabaseSafetyTest extends TestCase
{
    public static function unsafeConnections(): array
    {
        return [
            'cached live name' => [['driver' => 'mysql', 'database' => 'ezbizi']],
            'URL overrides safe name' => [['driver' => 'mysql', 'database' => 'ezbizi_testing_safe', 'url' => 'mysql://localhost/ezbizi']],
            'URL query overrides safe path' => [['driver' => 'mysql', 'url' => 'mysql://localhost/ezbizi_testing_safe?database=ezbizi']],
            'write override' => [['driver' => 'mysql', 'database' => 'ezbizi_testing_safe', 'write' => ['database' => 'ezbizi']]],
        ];
    }

    #[DataProvider('unsafeConnections')]
    public function test_refuses_effective_configuration_before_refresh_database(array $connection): void
    {
        $probe = new DatabaseSafetyProbe('probe');
        try {
            $probe->bootTraits($connection);
            self::fail('Unsafe configuration reached RefreshDatabase.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Refusing', $e->getMessage());
            self::assertFalse($probe->migrationReached);
        }
    }

    public function test_allows_isolated_database_without_opening_a_connection(): void
    {
        $probe = new DatabaseSafetyProbe('probe');
        $probe->bootTraits(['driver' => 'mysql', 'database' => 'ezbizi_testing_review_security']);
        self::assertTrue($probe->migrationReached);
    }

    public function test_checks_shared_schema_after_url_resolution(): void
    {
        $this->expectException(RuntimeException::class);
        DatabaseSafety::assertSafe([
            'driver' => 'mysql', 'database' => 'ezbizi_testing_safe',
            'url' => 'mysql://localhost/ezbizi_testing',
        ], windows: true, allowShared: false);
    }

    public function test_accepts_memory_sqlite_and_explicit_shared_override(): void
    {
        DatabaseSafety::assertSafe(['driver' => 'sqlite', 'database' => ':memory:'], true, false);
        DatabaseSafety::assertSafe(['driver' => 'mysql', 'database' => 'ezbizi_testing'], true, true);
        self::assertTrue(true);
    }
}

/** No DB manager is installed: migration is a harmless marker, never SQL. */
class DatabaseSafetyProbe extends \Tests\TestCase
{
    use RefreshDatabase;

    public bool $migrationReached = false;

    public function bootTraits(array $connection): void
    {
        $this->app = new Application;
        $this->app->instance('config', new Repository(['database' => [
            'default' => 'mysql', 'connections' => ['mysql' => $connection],
        ]]));
        $this->setUpTraits();
    }

    public function refreshDatabase(): void
    {
        $this->migrationReached = true;
    }
}
