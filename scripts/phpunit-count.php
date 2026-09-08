<?php

// Read the successful runner's structured result, not a locale-dependent console line.
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
echo 'EZBIZI_BACKEND_TESTS='.$tests.PHP_EOL;
