<?php
// "${var}" inside a double-quoted string is deprecated as of PHP 8.2 and is
// removed in PHP 9. "{$var}" is the supported form and behaves identically.
//
// This asks PHP's own tokenizer rather than matching text: the deprecated form
// is T_DOLLAR_OPEN_CURLY_BRACES, the supported one is T_CURLY_OPEN, so the two
// cannot be confused. Vendored code is excluded; it is not ours to modernise.

require_once __DIR__ . '/helper.php';

echo "sources: no string interpolation that PHP 9 removes\n";

$dirs = ['http_server', 'multiplayer_server', 'functions', 'common', 'policy_server'];
$files = [REPO . '/config.php'];

foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(REPO . '/' . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $files[] = $f->getPathname();
        }
    }
}

$offenders = [];
foreach ($files as $file) {
    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token) && $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(REPO) + 1));
            $offenders[] = $rel . ':' . $token[2];
        }
    }
}

ok(count($files) > 200, 'the scan covers the package sources (' . count($files) . ' files)');
is_same($offenders, [], 'no source uses the removed interpolation form');

t_done();
