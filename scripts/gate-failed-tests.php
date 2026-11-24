<?php

// php scripts/gate-failed-tests.php <junit.xml> <root>
//
// The test FILES that failed or errored in a gate run, one per line, relative to
// <root>. scripts/gate-backend.sh records them so the next cut can re-run just
// these (deploy.sh, "re-run only what failed"). Exit 1 when the report cannot be
// read, or when it carries a failure this cannot place in a file: then the next
// cut runs the full suite rather than a list that may be missing something.
$report = @simplexml_load_file($argv[1] ?? '');
$root = rtrim($argv[2] ?? '', '/').'/';
if ($report === false || $root === '/') {
    exit(1);
}
$files = [];
foreach ($report->xpath('//testcase[failure or error]') as $case) {
    $file = (string) $case['file'];
    if ($file === '' || ! str_starts_with($file, $root)) {
        exit(1);
    }
    $files[substr($file, strlen($root))] = true;
}
$failures = 0;
foreach ($report->testsuite as $suite) {
    $failures += (int) $suite['errors'] + (int) $suite['failures'];
}
// Every failure the totals count must be one of the test cases listed above.
if ($files === [] || $failures !== count($report->xpath('//testcase[failure or error]'))) {
    exit(1);
}
ksort($files);
echo implode(PHP_EOL, array_keys($files)).PHP_EOL;
