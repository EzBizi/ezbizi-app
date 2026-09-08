<?php

namespace Tests\Support;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

/** Validate configuration only: never connect to a database to decide if it is safe. */
final class DatabaseSafety
{
    public static function assertSafe(array|string $configuration, bool $windows, bool $allowShared): void
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        $database = $config['database'] ?? '';
        if (! (($config['driver'] ?? null) === 'sqlite' && $database === ':memory:')
            && ! str_contains((string) $database, 'testing')) {
            // Do not print URLs or credentials from the rejected configuration.
            throw new RuntimeException('Refusing tests: the effective database must contain testing in its name.');
        }
        if ($windows && $database === 'ezbizi_testing' && ! $allowShared) {
            throw new RuntimeException('Refusing tests against the shared ezbizi_testing schema. Set DB_DATABASE=ezbizi_testing_<module>.');
        }
        // Laravel merges read/write overrides into the parsed parent config.
        foreach (['read', 'write'] as $mode) {
            if (! isset($config[$mode])) {
                continue;
            }
            $variants = array_is_list($config[$mode]) ? $config[$mode] : [$config[$mode]];
            foreach ($variants as $override) {
                self::assertSafe(array_merge(array_diff_key($config, ['read' => true, 'write' => true]), $override), $windows, $allowShared);
            }
        }
    }
}
