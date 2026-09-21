<?php
// The suite reaches nothing outside the package.
//
// These tests used to live beside the package rather than in it, and they read
// from outside freely: the fixture set and the specification both sat in a
// documentation tree two directories up. Moving the suite in meant deciding
// what came with it, and the answer is the least that makes it run -- the
// tests, the stubs they drive, and the fixtures they read. No specification,
// no design notes, no threat model, nothing that is a thing to read rather
// than a thing to run.
//
// The reason is not tidiness. A repository is distributed to whoever can read
// it, so every file in it is published; documentation about where a system is
// weak is worth more to somebody looking for a way in than it is to the person
// who already knows. The code has to ship. The map does not.
//
// So this is the check that keeps it that way. A test that reaches up and out
// is a test that will fail in a fresh clone, and a fixture that reaches out is
// a document arriving by the back door.

require_once __DIR__ . '/helper.php';

echo "the suite reaches nothing outside the package\n";

// REPO is the package, and the suite is inside it.
ok(REPO === dirname(__DIR__), 'REPO is the package this suite lives in');
ok(is_file(REPO . '/config.php'), 'and the package is where it is expected to be');

// --- nothing names a path above the package --------------------------------
//
// The three ways out that existed before: the old layout's own name, the
// documentation tree, and stepping above REPO explicitly.

$escapes = array(
    'packages/platform-racing-2-server' => 'the path the suite had when it lived outside',
    'output/observer-spec'              => 'the documentation tree',
    'dirname(REPO)'                     => 'the directory above the package',
);

$found = array();
foreach (glob(__DIR__ . '/{*.php,stubs/*.php}', GLOB_BRACE) as $file) {
    $src = file_get_contents($file);
    $rel = basename(dirname($file)) === 'stubs'
        ? 'stubs/' . basename($file)
        : basename($file);
    if ($rel === basename(__FILE__)) {
        continue;
    }
    foreach ($escapes as $needle => $what) {
        if (strpos($src, $needle) !== false) {
            $found[] = "$rel names $what";
        }
    }
}
is_same($found, array(), 'no test or stub names a path above the package');

// --- and the documentation did not come with it ----------------------------
//
// Named individually rather than by a wildcard, because the point is that
// these specific documents are not shipped, and a wildcard would pass just as
// happily against a directory that does not exist.

foreach (array(
    'SPEC.md'                    => 'the observer specification',
    'FIXTURES.md'                => 'the fixture set\'s own description',
    'pr2-threat-model.md'        => 'the threat model',
    'pr2-observer-network.md'    => 'the build order',
) as $doc => $what) {
    $hits = array();
    $stack = array(REPO);
    while ($stack) {
        $dir = array_pop($stack);
        foreach (@scandir($dir) ?: array() as $e) {
            if ($e === '.' || $e === '..' || $e === '.git' || $e === 'clientsrc') {
                continue;
            }
            $path = "$dir/$e";
            if (is_dir($path)) {
                $stack[] = $path;
            } elseif ($e === $doc) {
                $hits[] = $path;
            }
        }
    }
    is_same($hits, array(), "$what is not shipped in the package");
}

// --- the fixtures are data, not prose --------------------------------------
//
// A fixture is a store tree and a manifest. Anything else in there would be
// documentation that arrived inside the one directory this file just allowed
// in.

$prose = array();
foreach (glob(REPO . '/tests/fixtures/*') as $entry) {
    if (is_file($entry)) {
        if (basename($entry) !== 'index.json') {
            $prose[] = basename($entry);
        }
        continue;
    }
    foreach (@scandir($entry) ?: array() as $e) {
        if ($e === '.' || $e === '..' || $e === 'manifest.json' || $e === 'stores') {
            $prose[] = basename($entry) . '/' . $e;
        }
    }
}
$prose = array_values(array_filter($prose, function ($p) {
    return substr($p, -2) !== '/.' && substr($p, -3) !== '/..';
}));
$prose = array_values(array_filter($prose, function ($p) {
    return !preg_match('~/(manifest\.json|stores)$~', $p);
}));
is_same($prose, array(), 'every fixture is a manifest and a store tree, and nothing else');

t_done();
