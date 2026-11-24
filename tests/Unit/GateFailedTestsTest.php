<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** scripts/gate-failed-tests.php: what the next cut may re-run instead of the whole suite (deploy.sh). */
class GateFailedTestsTest extends TestCase
{
    private const ROOT = '/opt/ezbizi-test';

    private function failedFiles(string $xml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ezbizi-junit-');
        try {
            file_put_contents($path, $xml);
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/gate-failed-tests.php', $path, self::ROOT]);

            return [$process->run(), trim(str_replace("\r", '', $process->getOutput()))];
        } finally {
            unlink($path);
        }
    }

    private static function report(string $cases, int $failures, int $errors): string
    {
        return '<testsuites><testsuite name="all" tests="9" failures="'.$failures.'" errors="'.$errors.'">'
            .'<testsuite name="Tests\Feature\ASweepTest" file="'.self::ROOT.'/tests/Feature/ASweepTest.php">'.$cases.'</testsuite>'
            .'</testsuite></testsuites>';
    }

    public function test_lists_each_failed_or_erroring_file_once_relative_to_the_root(): void
    {
        $root = self::ROOT;
        $xml = self::report(
            "<testcase name=\"a\" file=\"{$root}/tests/Feature/ASweepTest.php\"><failure>x</failure></testcase>"
            ."<testcase name=\"b\" file=\"{$root}/tests/Feature/ASweepTest.php\"><failure>y</failure></testcase>"
            ."<testcase name=\"c\" file=\"{$root}/tests/Unit/BTest.php\"><error>z</error></testcase>"
            ."<testcase name=\"d\" file=\"{$root}/tests/Unit/CTest.php\"/>",
            2, 1,
        );

        $this->assertSame([0, "tests/Feature/ASweepTest.php\ntests/Unit/BTest.php"], $this->failedFiles($xml));
    }

    public function test_refuses_when_a_counted_failure_has_no_test_case_to_place_it(): void
    {
        $root = self::ROOT;
        // The totals say two; only one test case carries a failure. The list would miss one.
        $xml = self::report("<testcase name=\"a\" file=\"{$root}/tests/Feature/ASweepTest.php\"><failure>x</failure></testcase>", 2, 0);

        $this->assertSame(1, $this->failedFiles($xml)[0]);
    }

    public function test_refuses_a_failure_outside_the_tree_and_a_report_with_none(): void
    {
        $this->assertSame(1, $this->failedFiles(self::report('<testcase name="a" file="/elsewhere/tests/XTest.php"><failure>x</failure></testcase>', 1, 0))[0]);
        $this->assertSame(1, $this->failedFiles(self::report('<testcase name="a" file="'.self::ROOT.'/tests/XTest.php"/>', 0, 0))[0]);
        $this->assertSame(1, $this->failedFiles('not xml')[0]);
    }
}
