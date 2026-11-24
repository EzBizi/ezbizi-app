<?php

// php scripts/phpunit-count.php <junit.xml> [<expected total>]
//
// Read the successful runner's structured result, not a locale-dependent console line.
// With an expected total (the gate passes `phpunit --list-tests`'s count), a report
// that ran fewer or more tests than the suite holds is refused: the parallel runner
// must account for every test, not only report none of them failing.
$report = simplexml_load_file($argv[1] ?? '');
if ($report === false) {
    exit(1);
}
$tests = 0;
foreach ($report->testsuite as $suite) {
    if ((int) $suite['errors'] !== 0 || (int) $suite['failures'] !== 0) {
        exit(1);
    }
    $tests += (int) $suite['tests'];
}
if ($tests < 1) {
    exit(1);
}
if (isset($argv[2]) && $tests !== (int) $argv[2]) {
    fwrite(STDERR, "✗ the report counts {$tests} tests; the suite lists {$argv[2]}".PHP_EOL);
    exit(1);
}
echo 'EZBIZI_BACKEND_TESTS='.$tests.PHP_EOL;
