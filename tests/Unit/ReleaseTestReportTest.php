<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ReleaseTestReportTest extends TestCase
{
    public function test_reports_total_and_refuses_failed_or_empty_results(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ezbizi-junit-');
        try {
            foreach ([
                ['<testsuites><testsuite tests="7" failures="0" errors="0"/><testsuite tests="3" failures="0" errors="0"/></testsuites>', 0, 'EZBIZI_BACKEND_TESTS=10'],
                ['<testsuites><testsuite tests="7" failures="1" errors="0"/></testsuites>', 1, ''],
                ['<testsuites/>', 1, ''],
            ] as [$xml, $status, $output]) {
                file_put_contents($path, $xml);
                $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/phpunit-count.php', $path]);
                self::assertSame($status, $process->run());
                self::assertSame($output, trim($process->getOutput()));
            }
        } finally {
            unlink($path);
        }
    }
}
