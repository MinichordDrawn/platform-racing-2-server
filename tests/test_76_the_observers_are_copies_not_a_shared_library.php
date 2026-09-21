<?php
// The observers are copies of one another, and they are copies rather than a
// shared library.
//
// Two different things are being asserted, and the distinction is the whole
// security argument.
//
// The one that must hold: nothing is shared at runtime. Four observers that
// load one file are one component wearing four names, and a single edit to
// that file makes all four lie at once. Copies do not have that property --
// after copying they are separate files, and an attacker must edit each.
// test_69 enforces that side.
//
// The one asserted here: the copies really are copies. The design argues for
// writing each observer independently from the specification; that was
// reconsidered and is recorded in the design document, because the
// independence that caught real defects was between the fixture set and the
// implementation, not between implementations. Having chosen copies, drift
// between them should be deliberate and recorded rather than accidental -- so
// the machinery is asserted identical, and anything that has to differ is
// listed here with its reason.
//
// The design's remaining prohibition stands and is checked: copies are made
// once by hand at authoring time. A generator, a shared include or a symlink
// would restore the single point this exists to remove while looking as
// though it had not.

require_once __DIR__ . '/helper.php';

echo "the observers are copies, not a shared library\n";

$root = REPO . '/observers';
$observers = array();
foreach (scandir($root) as $name) {
    if ($name !== '.' && $name !== '..' && is_dir("$root/$name")) {
        $observers[] = $name;
    }
}
sort($observers, SORT_STRING);

ok(count($observers) >= 2, 'there is more than one observer (' . implode(', ', $observers) . ')');

// The machinery: identical in every observer, modulo the namespace.
$machinery = array('store.php', 'procedures.php', 'log.php', 'traces.php', 'apply.php', 'cycle.php');

// The column: what each observer has because of where it runs.
//   run.php       -- the traces it reads, which only web has, since cron runs
//                    on web's host and nowhere else
//   schedules.php -- the declared task sets, for the same reason
$column = array('run.php', 'schedules.php');

function normalised($path, $observer)
{
    // Line endings are normalised first. They are not the subject here, and a
    // checkout on Windows turns them into CRLF, which stops the namespace line
    // below being recognised -- so every file looks different from every other
    // and the failure points at the wrong thing entirely.
    $s = str_replace("\r\n", "\n", file_get_contents($path));
    // The one line that is expected to differ.
    return preg_replace('/^namespace pr2obs\\\\[a-z]+;$/m', 'namespace OBSERVER;', $s);
}

$reference = $observers[0];

foreach ($machinery as $file) {
    $ref_path = "$root/$reference/$file";
    ok(is_file($ref_path), "$reference has $file");
    if (!is_file($ref_path)) {
        continue;
    }
    $ref = normalised($ref_path, $reference);

    foreach ($observers as $observer) {
        if ($observer === $reference) {
            continue;
        }
        $path = "$root/$observer/$file";
        if (!is_file($path)) {
            ok(false, "$observer is missing $file");
            continue;
        }
        is_same(
            hash('sha256', normalised($path, $observer)),
            hash('sha256', $ref),
            "$observer/$file is identical to $reference/$file"
        );
    }
}

// --- each observer declares its own namespace ----------------------------
//
// Not decoration: it is what lets a test harness load two of them at once
// without one silently answering for the other.
foreach ($observers as $observer) {
    foreach (glob("$root/$observer/*.php") as $path) {
        $src = str_replace("\r\n", "\n", file_get_contents($path));
        ok(
            preg_match('/^namespace pr2obs\\\\' . preg_quote($observer, '/') . ';$/m', $src) === 1,
            "$observer/" . basename($path) . ' declares its own namespace'
        );
    }
}

// --- the column is where it should be ------------------------------------
//
// container.php is the one file that is genuinely each observer's own: what
// its container is and must still be. If two observers' columns were
// identical, one of them would have been copied without being written, and it
// would be asserting another container's shape against its own.

$columns = array();
foreach ($observers as $observer) {
    $path = "$root/$observer/container.php";
    ok(is_file($path), "$observer declares the shape of its own container");
    if (is_file($path)) {
        $columns[$observer] = hash('sha256', normalised($path, $observer));
    }
}
is_same(
    count(array_unique($columns)),
    count($columns),
    'and no two observers share a column, which would mean one was never written'
);

// The things that must differ, because the containers differ.
$declared = array();
foreach ($observers as $observer) {
    $src = file_get_contents("$root/$observer/container.php");
    preg_match("/const CONTAINER_PROCESS = '([^']+)'/", $src, $m);
    $declared[$observer] = $m[1] ?? '(none)';
}
is_same(
    count(array_unique($declared)),
    count($declared),
    'each observer watches for a different process'
);

ok(is_file("$root/web/schedules.php"), 'web declares the schedules on its host');
foreach ($observers as $observer) {
    if ($observer === 'web') {
        continue;
    }
    ok(
        !is_file("$root/$observer/schedules.php"),
        "$observer declares no schedules, because no scheduled work runs on its host"
    );
}

// --- copies, not links, and nothing generates them -----------------------

$links = array();
foreach ($observers as $observer) {
    foreach (glob("$root/$observer/*") as $path) {
        if (is_link($path)) {
            $links[] = substr($path, strlen(REPO) + 1);
        }
    }
}
is_same($links, array(), 'no observer file is a symbolic link');

// A build step that produced these from one template would restore the single
// point they exist to remove. Nothing in the repository may generate them.
$generators = array();
foreach (array_merge(glob(REPO . '/docker/*'), glob(REPO . '/*.php'), glob(REPO . '/*.sh')) as $path) {
    if (!is_file($path)) {
        continue;
    }
    $src = file_get_contents($path);
    // A copy or template step that writes into observers/.
    if (preg_match('/(cp|rsync|sed|template|generate)[^\n]{0,60}observers\//i', $src)) {
        $generators[] = substr($path, strlen(REPO) + 1);
    }
}
is_same($generators, array(), 'nothing in the build generates an observer from another');

t_done();
