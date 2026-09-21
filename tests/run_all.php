<?php
// Runs every test file and reports one overall result.

$php = PHP_BINARY;
$dir = __DIR__;
$files = glob($dir . '/test_*.php');
sort($files);

$failed = [];
foreach ($files as $file) {
    $out = [];
    $code = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) {
        $failed[] = basename($file);
    }
}

echo str_repeat('-', 60) . "\n";
if ($failed) {
    echo count($failed) . " FAILING: " . implode(', ', $failed) . "\n";
    exit(1);
}
echo count($files) . " test files, all passing\n";
exit(0);
