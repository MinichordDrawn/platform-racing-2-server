<?php
// An observer can answer the signal it handles.
//
// Every observer installs a handler for SIGTERM and SIGINT and answers one by
// publishing a final heartbeat that records the stop as deliberate, so that a
// member which left on purpose does not read as a member that died. The
// handler is installed behind a guard, because the function that installs it
// belongs to an extension that need not be present.
//
// In the images this tree built, it was not present. The guard was false, no
// handler was installed, a signal killed the process outright, and the whole
// mechanism was dead code. Nothing said so: the observer started normally,
// reported itself healthy, and simply could not do the one thing that block
// exists for. It was found by signalling an observer to demonstrate a
// different fix and watching the ring report a stale heartbeat rather than a
// retirement.
//
// A safety mechanism that silently does nothing is worse than no mechanism,
// because it is read as cover. The absence was safe here by luck -- a stop
// that reads as a death halts the ring either way, which is the conservative
// direction -- and luck is not a property.
//
// Two things are checked. The extension is installed in every image that runs
// an observer, and it is declared in that observer's own column. The
// declaration is what makes this self-enforcing: `extensions-declared` fails
// when something declared is not loaded, so an image that ever ships without
// it faults and the ring halts, rather than quietly losing the mechanism
// again.

require_once __DIR__ . '/helper.php';

echo "an observer can answer the signal it handles\n";

// The image each observer runs in. Stated here rather than derived, because
// the mapping is a fact about the deployment and a test that read it out of
// the compose file would pass whatever the compose file said.
$images = array(
    'web'    => 'docker/http_server.dockerfile',
    'multi'  => 'docker/multi_server.dockerfile',
    'policy' => 'docker/policy_server.dockerfile',
    'super'  => 'docker/super_observer.dockerfile',
);

// Every observer directory is covered, so a fifth member cannot be added
// without this file noticing it has no image named here.
$found = array();
foreach (scandir(REPO . '/observers') as $name) {
    if ($name !== '.' && $name !== '..' && is_dir(REPO . "/observers/$name")) {
        $found[] = $name;
    }
}
sort($found, SORT_STRING);
$named = array_keys($images);
sort($named, SORT_STRING);
is_same($found, $named, 'every observer has an image named here');

// --- the extension the handler needs --------------------------------------

foreach ($images as $observer => $dockerfile) {
    $df = file_get_contents(REPO . '/' . $dockerfile);
    ok($df !== false, "$dockerfile exists");
    if ($df === false) {
        continue;
    }
    ok(
        preg_match('/docker-php-ext-install[^\n\\\\]*\bpcntl\b/', $df) === 1,
        "$observer's image installs the extension its signal handler needs"
    );
}

// --- and the observer declares it, which is what enforces it --------------

foreach ($images as $observer => $dockerfile) {
    $src = file_get_contents(REPO . "/observers/$observer/container.php");
    ok(
        preg_match("/const CONTAINER_EXTENSIONS = array\([^)]*'pcntl'/", $src) === 1,
        "$observer declares it, so an image without it faults rather than going quiet"
    );
}

// --- and an observer that declares an extension can report losing it ------
//
// Declaring the extension is only half of what makes the absence visible. The
// check that notices it is `extensions-declared`, and a check an observer does
// not list among its own is not treated as a statement about its own world: it
// halts the ring without writing a fault file, and it is absent from the
// heartbeat's list of what ran. The halt is the important half and it happens
// either way, so this is about the record rather than the stop.
//
// The super observer is the case. Its list was three checks long and correct
// while it declared no extensions at all, and stopped being correct the moment
// it declared one.

require_once REPO . '/observers/web/apply.php';

foreach (array_keys($images) as $observer) {
    $src = file_get_contents(REPO . "/observers/$observer/container.php");

    preg_match('/const CONTAINER_EXTENSIONS = array\(([^)]*)\)/', $src, $m);
    $declares = isset($m[1]) && trim($m[1]) !== '';

    preg_match('/function container_local_checks\(\): array\s*\{.*?return array\((.*?)\);/s', $src, $c);
    $listed = isset($c[1]) && strpos($c[1], "'extensions-declared'") !== false;

    ok(
        !$declares || $listed,
        "$observer lists the check that reports an extension it declares going missing"
    );
}
//
// Installing the extension does not make the guard unnecessary. An observer
// must still start where the extension is missing, because refusing to start
// would turn a lost diagnostic into a stopped deployment, and the loss is in
// the safe direction: a stop that cannot be recorded reads as a death, and a
// death halts the ring. The declaration above is what makes the condition
// visible; the guard is what keeps it from being fatal twice over.

foreach (array_keys($images) as $observer) {
    $run = file_get_contents(REPO . "/observers/$observer/run.php");
    ok(
        strpos($run, "function_exists('pcntl_async_signals')") !== false,
        "$observer still starts where the extension is absent"
    );
    ok(
        preg_match('/pcntl_signal\(SIGTERM/', $run) === 1,
        "$observer handles a termination signal"
    );
}

// --- what the handler is for ----------------------------------------------
//
// The record it writes is the only thing that tells a reader of the stores
// which of the two happened. Since V4 it no longer excuses the absence, so
// this is the whole of its remaining purpose and it should not quietly stop
// working again.

$run = file_get_contents(REPO . '/observers/web/run.php');
ok(
    preg_match("/\\\$plan\['publish'\]\['stop'\] = true;/", $run) === 1,
    'and answers it by recording that the stop was deliberate'
);

t_done();
