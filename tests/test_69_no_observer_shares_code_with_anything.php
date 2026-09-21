<?php
// No observer loads anything outside its own directory.
//
// This is the whole security argument of the observer network, expressed as
// the one test that can enforce it. Detection survives as long as one observer
// is honest, and evading it requires every member to lie at once. A shared
// helper hands that over for free: one edit to a file they all load, and they
// all lie together, with no need to compromise them separately. A common
// library is not a convenience here, it is the ring collapsing into a single
// component wearing four names.
//
// The same applies to the application. config.php, the function bootstraps and
// the query files are common to all the containers, so an observer that
// requires them is sharing code with the other observers through the back
// door. It earns a second property by refusing: an observer that does not
// parse the application cannot be stopped by a bug in the application it is
// watching.
//
// The specification is shared and the implementation is not. Four observers
// agree by being written to one written description, not by running one set of
// lines. Three copies means a fix applied three times and they will drift --
// that is accepted deliberately, because drift between independent
// implementations is visible as disagreement, which this structure is built to
// surface, while a single shared defect is invisible by construction.
//
// This test is enumerable and holds over observers that do not exist yet.

require_once __DIR__ . '/helper.php';

echo "no observer shares code with anything\n";

$root = REPO . '/observers';

ok(is_dir($root), 'the observers directory exists');
if (!is_dir($root)) {
    t_done();
}

// Each immediate subdirectory is one observer, written independently.
$observers = array();
foreach (scandir($root) as $name) {
    if ($name !== '.' && $name !== '..' && is_dir("$root/$name")) {
        $observers[] = $name;
    }
}
sort($observers, SORT_STRING);

ok(count($observers) >= 1, 'at least one observer is present (' . implode(', ', $observers) . ')');

// The application's own constants. An observer that uses one is an observer
// that has loaded config.php, because nothing else defines them -- so naming
// one is proof of exactly the coupling this forbids.
//
// Filenames are deliberately not on this list. An observer must be able to
// *name* the application's files, because hashing them is how it notices the
// code in its container changing while it runs. What it must not do is load
// them, and that is a different check: every require has to resolve inside the
// observer's own directory, which is asserted above. Naming a path as data and
// executing it are not the same act, and a rule that confused the two would
// forbid the code manifest outright.
$application = array(
    'GEN_HTTP_FNS', 'ALL_MULTI_FNS', 'QUERIES_DIR', 'FNS_DIR',
    'HTTP_FNS', 'PR2_FNS', 'COMMON_DIR', 'ROOT_DIR', 'WWW_ROOT', 'DATA_DIR',
    'CACHE_DIR', 'PR2_ROOT', 'SOCKET_DAEMON_FILES',
);

$outside = array();
$app_refs = array();
$cross = array();

foreach ($observers as $observer) {
    $dir = "$root/$observer";

    $files = array();
    $walk = function ($d) use (&$walk, &$files) {
        foreach (scandir($d) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$d/$e";
            if (is_dir($p)) {
                $walk($p);
            } elseif (substr($e, -4) === '.php') {
                $files[] = $p;
            }
        }
    };
    $walk($dir);

    ok(count($files) > 0, "$observer has source files");

    foreach ($files as $file) {
        $src = file_get_contents($file);
        $rel = substr($file, strlen(REPO) + 1);

        // Comments explain why these files load nothing; saying "require" in
        // prose is not requiring anything. Strip them before looking.
        $code = preg_replace('!/\*.*?\*/!s', '', $src);
        $code = preg_replace('/^\s*\/\/.*$/m', '', $code);

        // Every require/include must name a path under __DIR__, which for a
        // file in this tree is its own observer's directory. Anchored to the
        // start of a statement, so the word alone does not match.
        if (preg_match_all('/^\s*(?:require|include)(?:_once)?\s*\(?\s*([^;]+);/m', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $target = trim($hit[1]);
                if (strpos($target, '__DIR__') === false) {
                    $outside[] = "$rel: " . substr($target, 0, 60);
                    continue;
                }
                // __DIR__ . '/x.php' is fine; __DIR__ . '/../x.php' is not.
                if (strpos($target, '..') !== false) {
                    $outside[] = "$rel: " . substr($target, 0, 60);
                }
            }
        }

        // No application constant, bootstrap or configuration file.
        foreach ($application as $needle) {
            // Word-boundary match so DATA_DIR does not match a comment about
            // data directories, and config.php does not match a mention in
            // prose.
            $pattern = '/\b' . preg_quote($needle, '/') . '\b/';
            if (preg_match($pattern, $src)) {
                // Allow it inside comments: these files explain why they do
                // not load the application, and saying so is not doing it.
                $stripped = preg_replace('!/\*.*?\*/!s', '', $src);
                $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped);
                if (preg_match($pattern, $stripped)) {
                    $app_refs[] = "$rel: $needle";
                }
            }
        }

        // No observer may name another observer's directory.
        foreach ($observers as $other) {
            if ($other === $observer) {
                continue;
            }
            if (strpos($src, "observers/$other") !== false) {
                $cross[] = "$rel names observers/$other";
            }
        }
    }
}

is_same($outside, array(), 'no observer requires anything outside its own directory');
is_same($app_refs, array(), 'no observer loads or names the application');
is_same($cross, array(), 'no observer names another observer');

// --- and nothing loads the application on its behalf ----------------------
//
// The isolation is not only about what the source requires. auto_prepend_file
// puts config.php in front of every PHP process in these containers, which is
// exactly what carries the boot check to every request -- and it would put the
// whole application in front of an observer too: env.php, the database
// connection, the S3 client. An observer started without the prepend turned
// off has all of it loaded before its first line runs, and both properties the
// isolation buys are gone: it shares code with the other observers through the
// back door, and a bug in the application it is watching can stop it.
//
// Nothing in the observer's own source can prevent that, so it is asserted
// where the process is started.

$prepend_is_set = false;
foreach (glob(REPO . '/docker/*.dockerfile') as $df) {
    if (strpos(file_get_contents($df), 'prepend_file.ini') !== false) {
        $prepend_is_set = true;
    }
}
ok($prepend_is_set, 'the application prepend is installed, so this matters');

$starts = array();
foreach (array_merge(glob(REPO . '/docker/*.sh'), glob(REPO . '/docker/*.dockerfile')) as $file) {
    $rel = substr($file, strlen(REPO) + 1);
    foreach (explode("\n", file_get_contents($file)) as $line) {
        $line = trim(rtrim($line, "\r"));
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('#\bphp\b[^\n]*observers/[a-z0-9_-]+/run\.php#i', $line)) {
            continue;
        }
        $starts[] = array($rel, $line);
    }
}

ok(count($starts) > 0, 'an observer is started somewhere');

$unguarded = array();
foreach ($starts as $s) {
    if (strpos($s[1], 'auto_prepend_file=') === false) {
        $unguarded[] = $s[0] . ': ' . substr($s[1], 0, 60);
    }
}
is_same($unguarded, array(), 'every observer is started with the application prepend disabled');

// The specification each observer is written from is not shipped in this
// package, so there is nothing here to assert about it. It is deliberately
// absent: the package carries the code, the tests and the fixtures those
// tests read, and no documentation, because documentation is a thing to read
// rather than a thing to run.

t_done();
